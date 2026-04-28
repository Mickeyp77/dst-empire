<?php
/**
 * src/Empire/LawMonitor/AmendmentDrafter.php
 *
 * Produces a draft amendment document for an amendments table row.
 *
 * Strategy:
 *
 *  1. Load the amendments row + linked law_change + affected doc_renders.
 *  2. For each affected render, find its source doc_template.
 *  3. Re-render the template with an "AMENDMENT HEADER" prepended to the
 *     markdown — a change-track block that states what changed and why.
 *  4. Store the new render in doc_renders (via TemplateRenderer::render()).
 *  5. Set amendments.amendment_doc_render_id = new render ID, status = 'drafted'.
 *
 * PHASE B LIMITATION (documented):
 *  If the doc_template does NOT have a dedicated "amendment" variant, this
 *  drafter prepends an amendment notice block to the SAME template. This
 *  produces a legally informative but not court-ready amendment instrument.
 *  A proper "redline/markup" amendment requires a Phase B template type:
 *  'amendment' — to be added in migration 078. See AmendmentDrafter::draftFor()
 *  for the MICKEY-QUEUE note.
 *
 * Tenant isolation: every query filters tenant_id.
 *
 * Namespace: Mnmsos\Empire\LawMonitor
 */

namespace Mnmsos\Empire\LawMonitor;

use PDO;
use Mnmsos\Empire\Docs\TemplateRenderer;

class AmendmentDrafter
{
    private PDO              $db;
    private int              $tenantId;
    private TemplateRenderer $renderer;

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
        $this->renderer = new TemplateRenderer($db, $tenantId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Draft the amendment document for a given amendments.id.
     *
     * Flow:
     *  1. Load amendment row (tenant-isolated)
     *  2. Load linked law_change
     *  3. For each affected_doc_render_ids_json: load render → find template
     *  4. Re-render template with amendment header injected into variables
     *  5. Link new render to amendments row, set status='drafted'
     *
     * Returns the new doc_renders.id on success, null on failure.
     *
     * MICKEY-QUEUE: Phase B — add doc_templates.type = 'amendment' so each
     * base template can have a dedicated redline variant with {{#amendment_header}}
     * block. Until then, this method injects the header into the base template
     * via the __amendment_header__ variable (templates that don't declare it
     * will silently omit it — handled gracefully by TemplateRenderer).
     */
    public function draftFor(int $amendmentId): ?int
    {
        // ── Load amendment row ────────────────────────────────────────────────
        $amendment = $this->loadAmendment($amendmentId);
        if ($amendment === null) {
            fwrite(STDERR, "[AmendmentDrafter] amendment id={$amendmentId} not found for tenant={$this->tenantId}\n");
            return null;
        }

        if ($amendment['status'] === 'drafted' && $amendment['amendment_doc_render_id']) {
            // Already drafted — return existing render ID (idempotent)
            return (int)$amendment['amendment_doc_render_id'];
        }

        // ── Load law_change ───────────────────────────────────────────────────
        $lawChange = $this->loadLawChange((int)($amendment['law_change_id'] ?? 0));

        // ── Build amendment header markdown ───────────────────────────────────
        $headerMd = $this->buildAmendmentHeader($amendment, $lawChange);

        // ── Find affected renders ─────────────────────────────────────────────
        $affectedRenderIds = $this->decodeAffectedRenderIds($amendment['affected_doc_render_ids_json'] ?? null);

        // Determine the primary render to amend (first in list, or latest for intake)
        $primaryRenderId = !empty($affectedRenderIds)
            ? $affectedRenderIds[0]
            : $this->findLatestRenderForIntake((int)($amendment['intake_id'] ?? 0));

        if ($primaryRenderId === null) {
            // No existing renders — create a standalone amendment notice doc
            $newRenderId = $this->createStandaloneAmendmentDoc($amendment, $lawChange, $headerMd);
        } else {
            $newRenderId = $this->reRenderWithAmendmentHeader($primaryRenderId, $amendment, $headerMd);
        }

        if ($newRenderId === null) {
            fwrite(STDERR, "[AmendmentDrafter] Failed to produce render for amendment id={$amendmentId}\n");
            return null;
        }

        // ── Update amendment row ──────────────────────────────────────────────
        $this->linkAmendmentRender($amendmentId, $newRenderId);

        return $newRenderId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Amendment header builder
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the Markdown amendment header block — change-track notice.
     *
     * @param array<string,mixed>  $amendment
     * @param ?array<string,mixed> $lawChange
     */
    private function buildAmendmentHeader(array $amendment, ?array $lawChange): string
    {
        $today      = date('F j, Y');
        $severity   = strtoupper($amendment['severity'] ?? 'MEDIUM');
        $lawTitle   = $lawChange ? ($lawChange['title'] ?? 'Law Change') : 'Regulatory Change';
        $lawSource  = $lawChange ? ($lawChange['source'] ?? '') : '';
        $lawUrl     = $lawChange ? ($lawChange['source_url'] ?? '') : '';
        $lawSummary = '';

        if ($lawChange) {
            $classJson = $lawChange['classification_json'] ?? null;
            if ($classJson) {
                $cls = is_string($classJson) ? json_decode($classJson, true) : $classJson;
                $lawSummary = is_array($cls) ? ($cls['summary_md'] ?? '') : '';
            }
        }

        $header  = "---\n";
        $header .= "**AMENDMENT NOTICE** | Severity: {$severity} | Drafted: {$today}\n\n";
        $header .= "This document has been flagged for amendment due to the following law change:\n\n";
        $header .= "**{$lawTitle}**";
        if ($lawSource) {
            $header .= " *(Source: {$lawSource})*";
        }
        $header .= "\n\n";

        if ($lawSummary) {
            $header .= $lawSummary . "\n\n";
        }

        if ($lawUrl) {
            $header .= "Reference: <{$lawUrl}>\n\n";
        }

        $header .= "**Recommended action:** " . ($amendment['trigger_description_md'] ?? 'Review with your attorney.') . "\n\n";
        $header .= "---\n\n";
        $header .= "> **DRAFT** — This amendment draft requires attorney review before execution.\n\n";
        $header .= "---\n\n";

        return $header;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Rendering strategies
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Re-render the source template of an existing render, injecting the
     * amendment header via the __amendment_header__ variable.
     *
     * @param array<string,mixed> $amendment
     */
    private function reRenderWithAmendmentHeader(int $sourceRenderId, array $amendment, string $headerMd): ?int
    {
        // Load the original render to get its template_id and variables_json
        $originalRender = $this->renderer->getRender($sourceRenderId);
        if ($originalRender === null) {
            return $this->createStandaloneAmendmentDoc($amendment, null, $headerMd);
        }

        $templateId = (int)($originalRender['template_id'] ?? 0);
        if ($templateId === 0) {
            return $this->createStandaloneAmendmentDoc($amendment, null, $headerMd);
        }

        // Reconstruct variable set from the original render
        $origVarsJson = $originalRender['variables_json'] ?? '{}';
        $origVars     = is_string($origVarsJson) ? (json_decode($origVarsJson, true) ?? []) : ($origVarsJson ?? []);
        if (!is_array($origVars)) {
            $origVars = [];
        }

        // Inject amendment header into variables
        $origVars['__amendment_header__'] = $headerMd;
        $origVars['__is_amendment__']     = true;
        $origVars['__amendment_date__']   = date('Y-m-d');
        $origVars['__amendment_id__']     = (int)$amendment['id'];

        try {
            $intakeId = isset($amendment['intake_id']) ? (int)$amendment['intake_id'] : null;
            $result   = $this->renderer->render($templateId, $origVars, $intakeId);
            return $result['id'] ?? null;
        } catch (\Throwable $e) {
            fwrite(STDERR, '[AmendmentDrafter] re-render failed template_id=' . $templateId . ': ' . $e->getMessage() . "\n");
            // Fall through to standalone
            return $this->createStandaloneAmendmentDoc($amendment, null, $headerMd);
        }
    }

    /**
     * Create a standalone amendment notice document when no base template exists.
     * Uses the generic amendment notice template (template slug = 'amendment_notice').
     * If that template doesn't exist, inserts a raw render directly.
     *
     * @param array<string,mixed>  $amendment
     * @param ?array<string,mixed> $lawChange
     */
    private function createStandaloneAmendmentDoc(array $amendment, ?array $lawChange, string $headerMd): ?int
    {
        // Try named template first
        $templateId = $this->findAmendmentNoticeTemplate();

        if ($templateId !== null) {
            try {
                $vars = [
                    '__amendment_header__' => $headerMd,
                    '__is_amendment__'     => true,
                    '__amendment_date__'   => date('Y-m-d'),
                    '__amendment_id__'     => (int)$amendment['id'],
                    'intake_id'            => $amendment['intake_id'] ?? null,
                ];
                $intakeId = isset($amendment['intake_id']) ? (int)$amendment['intake_id'] : null;
                $result   = $this->renderer->render($templateId, $vars, $intakeId);
                return $result['id'] ?? null;
            } catch (\Throwable $e) {
                fwrite(STDERR, '[AmendmentDrafter] amendment_notice template render failed: ' . $e->getMessage() . "\n");
            }
        }

        // Last resort: insert a raw doc_render row with the amendment notice as content
        // MICKEY-QUEUE: Phase B — replace with dedicated amendment template type
        // when migration 078 adds doc_templates.type = 'amendment'
        return $this->insertRawAmendmentRender($amendment, $headerMd);
    }

    /**
     * Insert a raw doc_renders row directly (bypasses TemplateRenderer).
     * Used as final fallback when no template is available.
     *
     * @param array<string,mixed> $amendment
     */
    private function insertRawAmendmentRender(array $amendment, string $headerMd): ?int
    {
        try {
            $intakeId = isset($amendment['intake_id']) ? (int)$amendment['intake_id'] : null;
            $hash     = hash('sha256', $headerMd);

            $stmt = $this->db->prepare(
                "INSERT INTO doc_renders
                 (tenant_id, template_id, intake_id, variables_json,
                  rendered_md, content_hash, version, status, created_at)
                 VALUES
                 (?, NULL, ?, '{}', ?, ?, 1, 'draft', NOW())"
            );
            $stmt->execute([
                $this->tenantId,
                $intakeId,
                $headerMd,
                $hash,
            ]);

            return (int)$this->db->lastInsertId() ?: null;

        } catch (\Throwable $e) {
            fwrite(STDERR, '[AmendmentDrafter] raw render insert failed: ' . $e->getMessage() . "\n");
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — DB helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return ?array<string,mixed> */
    private function loadAmendment(int $amendmentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM amendments
             WHERE id = ? AND tenant_id = ?
             LIMIT 1"
        );
        $stmt->execute([$amendmentId, $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return ?array<string,mixed> */
    private function loadLawChange(int $lawChangeId): ?array
    {
        if ($lawChangeId === 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM law_changes WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$lawChangeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findLatestRenderForIntake(int $intakeId): ?int
    {
        if ($intakeId === 0) {
            return null;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM doc_renders
                 WHERE intake_id = ?
                   AND status NOT IN ('superseded','archived')
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute([$intakeId]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function findAmendmentNoticeTemplate(): ?int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM doc_templates
                 WHERE slug = 'amendment_notice'
                   AND (tenant_id = ? OR is_system = 1)
                 ORDER BY is_system ASC
                 LIMIT 1"
            );
            $stmt->execute([$this->tenantId]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function linkAmendmentRender(int $amendmentId, int $renderId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE amendments
             SET amendment_doc_render_id = ?,
                 status = 'drafted'
             WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$renderId, $amendmentId, $this->tenantId]);
    }

    /** @return int[] */
    private function decodeAffectedRenderIds(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_map('intval', array_filter($decoded, 'is_numeric'));
    }
}
