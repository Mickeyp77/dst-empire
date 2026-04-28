<?php
/**
 * TemplateRenderer — fills doc_templates rows with client variable values.
 *
 * Syntax supported (no external library — CARL rule 4):
 *   {{ var_name }}                         — simple substitution
 *   {{ var_name | default('fallback') }}   — with default
 *   {{#if has_partner}}...{{/if}}          — conditional section
 *   {{#each beneficial_owners}}...{{/each}} — loop (var must be array)
 *   Inside each loop body: {{ item.field }} or {{ field }} refer to the
 *   current iteration item's keys.
 *
 * Records every successful render to doc_renders (schema from mig 077).
 * Returns missing_vars without rendering if required vars are absent.
 */

namespace Mnmsos\Empire\Docs;

use PDO;
use RuntimeException;

class TemplateRenderer
{
    private PDO $db;
    private int $tenantId;

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Render a template, record to doc_renders, return result.
     *
     * @param int   $templateId  doc_templates.id
     * @param array $variables   flat key→value map (scalar or array for loops)
     * @param int|null $intakeId empire_brand_intake.id (NULL = tenant-level doc)
     * @return array {
     *   rendered_md:  string   — filled markdown (empty if missing_vars)
     *   content_hash: string   — SHA-256 of rendered_md (empty if missing)
     *   missing_vars: string[] — list of required var names that were absent
     *   render_id:    int|null — doc_renders.id (null if not saved)
     * }
     */
    public function render(int $templateId, array $variables, ?int $intakeId = null): array
    {
        $tpl = $this->fetchTemplate($templateId);
        if ($tpl === null) {
            throw new RuntimeException("Template #{$templateId} not found.");
        }

        // ── 1. Validate required vars ─────────────────────────────────────
        $missing = $this->checkRequired($tpl, $variables);
        if (!empty($missing)) {
            return [
                'rendered_md'  => '',
                'content_hash' => '',
                'missing_vars' => $missing,
                'render_id'    => null,
            ];
        }

        // ── 2. Render ─────────────────────────────────────────────────────
        $md   = (string)($tpl['template_md'] ?? '');
        $md   = $this->renderEach($md, $variables);
        $md   = $this->renderIf($md, $variables);
        $md   = $this->renderVars($md, $variables);

        $hash = hash('sha256', $md);

        // ── 3. Persist to doc_renders ─────────────────────────────────────
        $version  = $this->nextVersion($templateId, $intakeId);
        $renderId = $this->insertRender($templateId, $intakeId, $variables, $md, $hash, $version);

        return [
            'rendered_md'  => $md,
            'content_hash' => $hash,
            'missing_vars' => [],
            'render_id'    => $renderId,
        ];
    }

    /**
     * Fetch a previously saved render by its doc_renders.id.
     *
     * @return array|null Row from doc_renders or null.
     */
    public function getRender(int $renderId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM doc_renders WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$renderId, $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List all renders for a given intake_id, ordered newest first.
     *
     * @return array[]
     */
    public function listForIntake(int $intakeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dr.*, dt.name AS template_name, dt.category, dt.slug
             FROM   doc_renders  dr
             JOIN   doc_templates dt ON dt.id = dr.template_id
             WHERE  dr.tenant_id = ? AND dr.intake_id = ?
             ORDER  BY dr.created_at DESC"
        );
        $stmt->execute([$this->tenantId, $intakeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Promote a render's status.
     *
     * @param string $status  One of: draft | attorney_review | client_approved | filed | superseded
     */
    public function updateStatus(int $renderId, string $status): bool
    {
        $allowed = ['draft', 'attorney_review', 'client_approved', 'filed', 'superseded'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE doc_renders SET status = ? WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$status, $renderId, $this->tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Store file paths for an existing render (after Pandoc conversion).
     */
    public function storeFilePaths(int $renderId, ?string $pdfPath, ?string $docxPath): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE doc_renders
             SET file_path_pdf  = COALESCE(?, file_path_pdf),
                 file_path_docx = COALESCE(?, file_path_docx)
             WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$pdfPath, $docxPath, $renderId, $this->tenantId]);
        return $stmt->rowCount() >= 0; // 0 affected = paths unchanged, still OK
    }

    // ── Variable engine ───────────────────────────────────────────────────

    /**
     * Render {{ var }} and {{ var | default('x') }} tokens.
     *
     * @param string $md        Template markdown
     * @param array  $variables Flat key→value map (used at this call-site — may be
     *                          the loop-item scope when called from renderEach)
     */
    private function renderVars(string $md, array $variables): string
    {
        // {{ var_name | default('fallback text') }}
        $md = preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\|\s*default\([\'"]([^\'"]*)[\'\"]\)\s*\}\}/',
            function (array $m) use ($variables): string {
                $val = $this->resolveVar($m[1], $variables);
                return $val !== null ? (string)$val : $m[2];
            },
            $md
        );

        // {{ var_name }} — plain
        $md = preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            function (array $m) use ($variables): string {
                $val = $this->resolveVar($m[1], $variables);
                return $val !== null ? (string)$val : '';
            },
            $md
        );

        return $md;
    }

    /**
     * Render {{#if flag}}...{{/if}} blocks.
     * flag is truthy if: non-empty string, non-zero int, true bool, non-empty array.
     * Nested ifs are NOT supported (not needed for P0 templates).
     */
    private function renderIf(string $md, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{#if\s+([\w.]+)\s*\}\}(.*?)\{\{\/if\}\}/s',
            function (array $m) use ($variables): string {
                $val = $this->resolveVar($m[1], $variables);
                $truthy = !empty($val);
                return $truthy ? $m[2] : '';
            },
            $md
        );
    }

    /**
     * Render {{#each collection}}...{{/each}} blocks.
     * collection must be an array of associative arrays.
     * Inside the block: {{ field }} refers to the current item's keys.
     */
    private function renderEach(string $md, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{#each\s+([\w.]+)\s*\}\}(.*?)\{\{\/each\}\}/s',
            function (array $m) use ($variables): string {
                $collection = $this->resolveVar($m[1], $variables);
                if (!is_array($collection) || empty($collection)) {
                    return '';
                }
                $body   = $m[2];
                $output = '';
                $i      = 0;
                foreach ($collection as $item) {
                    if (!is_array($item)) {
                        $item = ['value' => $item];
                    }
                    // Inject loop index helpers
                    $item['@index']   = $i;
                    $item['@number']  = $i + 1;
                    $item['@first']   = ($i === 0) ? 'true' : '';
                    $item['@last']    = ($i === count($collection) - 1) ? 'true' : '';
                    $block = $this->renderVars($body, $item);
                    $block = $this->renderIf($block, $item);
                    $output .= $block;
                    $i++;
                }
                return $output;
            },
            $md
        );
    }

    /**
     * Resolve a dot-notation variable path from the variables map.
     * Supports simple 'key' or 'key.subkey' for arrays.
     *
     * @return mixed|null  null if not found
     */
    private function resolveVar(string $path, array $variables)
    {
        $parts = explode('.', $path);
        $val   = $variables;
        foreach ($parts as $part) {
            if (is_array($val) && array_key_exists($part, $val)) {
                $val = $val[$part];
            } else {
                return null;
            }
        }
        return $val;
    }

    // ── Validation ────────────────────────────────────────────────────────

    /**
     * Return list of required variable names missing from $variables.
     * A variable is required if its variables_json entry has no 'default' key
     * (or default is null) AND the variable is not present in $variables.
     */
    private function checkRequired(array $tpl, array $variables): array
    {
        $missing   = [];
        $varSchema = json_decode((string)($tpl['variables_json'] ?? '[]'), true);
        if (!is_array($varSchema)) {
            return [];
        }
        foreach ($varSchema as $varDef) {
            if (!is_array($varDef)) {
                continue;
            }
            $name     = $varDef['name'] ?? '';
            $hasDefault = array_key_exists('default', $varDef) && $varDef['default'] !== null;
            if ($name === '') {
                continue;
            }
            if ($hasDefault) {
                continue; // optional
            }
            // Check presence in variables (dot-notation supported)
            $val = $this->resolveVar($name, $variables);
            if ($val === null || $val === '') {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    // ── DB helpers ────────────────────────────────────────────────────────

    private function fetchTemplate(int $templateId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM doc_templates WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function nextVersion(int $templateId, ?int $intakeId): int
    {
        if ($intakeId === null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(MAX(version_number), 0) + 1
                 FROM   doc_renders
                 WHERE  template_id = ? AND tenant_id = ? AND intake_id IS NULL"
            );
            $stmt->execute([$templateId, $this->tenantId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(MAX(version_number), 0) + 1
                 FROM   doc_renders
                 WHERE  template_id = ? AND tenant_id = ? AND intake_id = ?"
            );
            $stmt->execute([$templateId, $this->tenantId, $intakeId]);
        }
        return (int)$stmt->fetchColumn();
    }

    private function insertRender(
        int    $templateId,
        ?int   $intakeId,
        array  $variables,
        string $md,
        string $hash,
        int    $version
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO doc_renders
                (tenant_id, intake_id, template_id, variables_used_json,
                 rendered_md, content_hash, version_number, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')"
        );
        $stmt->execute([
            $this->tenantId,
            $intakeId,
            $templateId,
            json_encode($variables, JSON_UNESCAPED_UNICODE),
            $md,
            $hash,
            $version,
        ]);
        return (int)$this->db->lastInsertId();
    }
}
