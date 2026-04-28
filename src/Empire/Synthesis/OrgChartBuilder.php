<?php
/**
 * OrgChartBuilder — hierarchical node-graph builder for the DST Empire org chart.
 *
 * Produces a JSON-serializable graph (nodes + edges + layout_hints) for Canvas 2D rendering.
 *
 * 4-layer structure (per master spec §4A):
 *   L0: Owner (Mickey / natural person)
 *   L1: Trusts (Dynasty, DAPT, Bridge — if recommended)
 *   L2: Holding / HoldCo (MNMS LLC S-Corp)
 *   L3: OpCo entities (per-brand LLCs, C-Corp)
 *   L4: DBAs / Series cells (operating under OpCo)
 *
 * Edge kinds:
 *   ownership   — equity interest (pct)
 *   ip_license  — royalty flow (royalty_pct)
 *   mgmt_fee    — management fee (pct of revenue)
 *   distribution — cash dividend/distribution
 *   loan        — intercompany loan
 *   beneficiary — trust beneficiary relationship
 *   trustee     — trustee relationship
 *
 * Uses decided_* fields from intake (locked decisions) where available;
 * falls back to BrandPlacement::analyze() recommendations otherwise.
 * BrandPlacement is loaded via require_once if not yet autoloaded.
 */

namespace Mnmsos\Empire\Synthesis;

use PDO;

class OrgChartBuilder
{
    private PDO $db;
    private int $tenantId;

    // Known MNMS holding company constants
    private const HOLDCO_SLUG  = 'mnms';
    private const HOLDCO_LABEL = 'MNMS LLC';
    private const HOLDCO_JUR   = 'TX';
    private const HOLDCO_FORM  = 'S-Corp';

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Build org chart from intake rows + playbook recommendations.
     *
     * @param array $intakeRows    Rows from empire_brand_intake (mig 077 columns)
     * @param array $playbookRecs  Keyed by brand_slug → array of playbook results
     * @param array $portfolioCtx  Row from empire_portfolio_context
     * @param array &$blockers     Appended in-place for data gaps
     */
    public function build(
        array $intakeRows,
        array $playbookRecs,
        array $portfolioCtx,
        array &$blockers = []
    ): array {
        $nodes = [];
        $edges = [];

        // ----------------------------------------------------------------
        // L0: Owner node
        // ----------------------------------------------------------------
        $ownerName = $portfolioCtx['owner_name'] ?? 'Mickey P';
        $ownerNode = [
            'id'    => 'owner',
            'type'  => 'owner',
            'layer' => 0,
            'label' => $ownerName,
            'meta'  => [
                'domicile'          => $portfolioCtx['owner_domicile_state'] ?? 'TX',
                'age'               => $portfolioCtx['owner_age'] ?? null,
                'estate_plan_status' => $portfolioCtx['estate_plan_status'] ?? 'none',
                'tx_sos_current'    => $portfolioCtx['tx_sos_current'] ?? null,
                'irs_status'        => $portfolioCtx['irs_status'] ?? 'current',
            ],
        ];
        $nodes['owner'] = $ownerNode;

        // ----------------------------------------------------------------
        // L1: Trust nodes (only if recommended by playbooks)
        // ----------------------------------------------------------------
        $trustNodes = $this->buildTrustNodes($intakeRows, $playbookRecs, $portfolioCtx, $blockers);
        foreach ($trustNodes as $tn) {
            $nodes[$tn['id']] = $tn;
            // owner → trust: trustee edge
            $edges[] = [
                'from'  => 'owner',
                'to'    => $tn['id'],
                'kind'  => 'trustee',
                'label' => $tn['meta']['trust_type'] . ' — trustee',
            ];
            // trust → holdco: ownership edge (if trust owns MNMS)
            if ($tn['meta']['owns_holdco'] ?? false) {
                $edges[] = [
                    'from'  => $tn['id'],
                    'to'    => self::HOLDCO_SLUG,
                    'kind'  => 'ownership',
                    'pct'   => $tn['meta']['ownership_pct'] ?? 100,
                    'label' => ($tn['meta']['ownership_pct'] ?? 100) . '% owns',
                ];
            }
            // owner → trust: beneficiary edge
            $edges[] = [
                'from'  => 'owner',
                'to'    => $tn['id'],
                'kind'  => 'beneficiary',
                'label' => 'beneficiary',
            ];
        }

        // ----------------------------------------------------------------
        // L2: HoldCo node (MNMS LLC S-Corp — always present)
        // ----------------------------------------------------------------
        $holdcoMeta = $this->fetchHoldcoMeta($portfolioCtx);
        $holdcoNode = [
            'id'    => self::HOLDCO_SLUG,
            'type'  => 'holdco',
            'layer' => 2,
            'label' => self::HOLDCO_LABEL,
            'meta'  => $holdcoMeta,
        ];
        $nodes[self::HOLDCO_SLUG] = $holdcoNode;

        // owner → holdco (if no trust owns holdco, owner owns directly)
        $holdcoOwnedByTrust = false;
        foreach ($trustNodes as $tn) {
            if ($tn['meta']['owns_holdco'] ?? false) {
                $holdcoOwnedByTrust = true;
                break;
            }
        }
        if (!$holdcoOwnedByTrust) {
            $edges[] = [
                'from'  => 'owner',
                'to'    => self::HOLDCO_SLUG,
                'kind'  => 'ownership',
                'pct'   => 100,
                'label' => '100% owns',
            ];
        }

        // ----------------------------------------------------------------
        // IP Holding entity (if any playbook recommends IP-Co)
        // ----------------------------------------------------------------
        $ipCoNode = $this->buildIpCoNode($intakeRows, $playbookRecs, $blockers);
        if ($ipCoNode) {
            $nodes[$ipCoNode['id']] = $ipCoNode;
            $edges[] = [
                'from'  => self::HOLDCO_SLUG,
                'to'    => $ipCoNode['id'],
                'kind'  => 'ownership',
                'pct'   => 100,
                'label' => '100% owns',
            ];
        }

        // ----------------------------------------------------------------
        // L3: OpCo nodes — one per intake row that has an entity structure
        // ----------------------------------------------------------------
        foreach ($intakeRows as $row) {
            $slug = $row['brand_slug'] ?? null;
            if (!$slug || $slug === self::HOLDCO_SLUG) {
                continue;
            }

            $entityType = $row['decided_entity_type'] ?? null;
            $jur        = $row['decided_jurisdiction'] ?? null;
            $tier       = $row['tier'] ?? null;

            // Determine entity structure: prefer decided_* cols, else use BrandPlacement output
            if (!$entityType || !$jur) {
                $bpRec = $this->getBrandPlacementRec($row, $blockers);
                $entityType = $entityType ?? ($bpRec['recommendation']['entity_type'] ?? 'llc');
                $jur        = $jur ?? ($bpRec['recommendation']['jurisdiction'] ?? 'TX');
            }

            // Skip DBA-only brands (keep_dba) — they live under L4 of their parent
            if ($entityType === 'keep_dba') {
                // Will be added as L4 DBA node under holdco
                $dbaNode = [
                    'id'    => $slug,
                    'type'  => 'dba',
                    'layer' => 4,
                    'label' => $row['brand_name'] ?? $slug,
                    'meta'  => $this->buildEntityMeta($row, $entityType, $jur),
                ];
                $nodes[$slug] = $dbaNode;
                $edges[] = [
                    'from'  => self::HOLDCO_SLUG,
                    'to'    => $slug,
                    'kind'  => 'ownership',
                    'pct'   => 100,
                    'label' => 'DBA of ' . self::HOLDCO_LABEL,
                ];
                continue;
            }

            $isSeriesCell = ($row['is_series_cell'] ?? false) || $this->isSeriesCell($row);
            $parentId     = $isSeriesCell ? $this->seriesParentId($row) : self::HOLDCO_SLUG;

            $opcoNode = [
                'id'    => $slug,
                'type'  => $entityType === 'c_corp' ? 'ccorp' : 'llc',
                'layer' => 3,
                'label' => $row['brand_name'] ?? $slug,
                'meta'  => $this->buildEntityMeta($row, $entityType, $jur),
            ];
            $nodes[$slug] = $opcoNode;

            // holdco (or series parent) → opco: ownership
            $edges[] = [
                'from'  => $parentId,
                'to'    => $slug,
                'kind'  => 'ownership',
                'pct'   => 100,
                'label' => '100% owns',
            ];

            // IP royalty edge: opco → ipco (if ipco exists and brand has IP)
            if ($ipCoNode && $this->hasIpExposure($row)) {
                $royaltyPct = $this->royaltyPct($row);
                $edges[] = [
                    'from'       => $slug,
                    'to'         => $ipCoNode['id'],
                    'kind'       => 'ip_license',
                    'royalty_pct' => $royaltyPct,
                    'label'      => "{$royaltyPct}% IP royalty",
                ];
            }

            // Mgmt fee edge: opco → holdco
            $mgmtFeePct = $this->mgmtFeePct($row, $playbookRecs[$slug] ?? []);
            if ($mgmtFeePct > 0) {
                $edges[] = [
                    'from'   => $slug,
                    'to'     => self::HOLDCO_SLUG,
                    'kind'   => 'mgmt_fee',
                    'pct'    => $mgmtFeePct,
                    'label'  => "Mgmt fee {$mgmtFeePct}%",
                ];
            }

            // Distribution edge: opco → owner (or trust)
            $distTarget = $holdcoOwnedByTrust ? array_key_first($trustNodes) ?? 'owner' : 'owner';
            $edges[] = [
                'from'  => $slug,
                'to'    => self::HOLDCO_SLUG,
                'kind'  => 'distribution',
                'label' => 'distributions',
            ];
        }

        // ----------------------------------------------------------------
        // Compute tree depth
        // ----------------------------------------------------------------
        $maxLayer = 0;
        foreach ($nodes as $n) {
            $maxLayer = max($maxLayer, (int)($n['layer'] ?? 0));
        }

        return [
            'nodes'        => array_values($nodes),
            'edges'        => $edges,
            'layout_hints' => [
                'root_node_id' => 'owner',
                'tree_depth'   => $maxLayer + 1,
                'holdco_id'    => self::HOLDCO_SLUG,
                'node_count'   => count($nodes),
                'edge_count'   => count($edges),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Trust layer
    // -----------------------------------------------------------------------

    /**
     * Build trust nodes from playbook recommendations.
     * Trust threshold logic (from TrustBuilder pattern):
     *   WY DAPT: $300k+ assets
     *   NV/SD DAPT: $500k+ assets
     *   SD Dynasty: $1M+ assets
     *   Bridge Trust: $2M+ assets
     */
    private function buildTrustNodes(
        array $intakeRows,
        array $playbookRecs,
        array $portfolioCtx,
        array &$blockers
    ): array {
        $trustNodes = [];

        // Check playbook recs for trust recommendations
        foreach ($playbookRecs as $slug => $recs) {
            if (!is_array($recs)) {
                continue;
            }
            foreach ($recs as $pb) {
                if (!isset($pb['applies']) || !$pb['applies']) {
                    continue;
                }
                $pbId = $pb['playbook_id'] ?? '';
                // Trust playbooks identify themselves with 'trust' in ID
                if (strpos($pbId, 'trust') === false && strpos($pbId, 'dapt') === false) {
                    continue;
                }
                $trustType = $pb['trust_type'] ?? 'dapt';
                $tid       = 'trust_' . $trustType;
                if (isset($trustNodes[$tid])) {
                    continue; // Already added
                }
                $trustNodes[$tid] = [
                    'id'    => $tid,
                    'type'  => 'trust',
                    'layer' => 1,
                    'label' => $pb['trust_label'] ?? strtoupper($trustType),
                    'meta'  => [
                        'trust_type'    => $trustType,
                        'jurisdiction'  => $pb['trust_jurisdiction'] ?? 'SD',
                        'owns_holdco'   => $pb['trust_owns_holdco'] ?? false,
                        'ownership_pct' => $pb['trust_ownership_pct'] ?? 0,
                        'note'          => $pb['trust_note'] ?? '',
                    ],
                ];
            }
        }

        // If no trust playbooks fired but portfolio says estate plan exists, add a placeholder
        $estatePlan = $portfolioCtx['estate_plan_status'] ?? 'none';
        if (empty($trustNodes) && in_array($estatePlan, ['revocable_trust', 'irrevocable_trust', 'dapt', 'dynasty'], true)) {
            $trustNodes['trust_existing'] = [
                'id'    => 'trust_existing',
                'type'  => 'trust',
                'layer' => 1,
                'label' => 'Existing Trust',
                'meta'  => [
                    'trust_type'   => $estatePlan,
                    'jurisdiction' => $portfolioCtx['owner_domicile_state'] ?? 'TX',
                    'owns_holdco'  => false,
                    'ownership_pct' => 0,
                    'note'         => 'Sourced from portfolio context estate_plan_status.',
                ],
            ];
        }

        return $trustNodes;
    }

    // -----------------------------------------------------------------------
    // IP-Co
    // -----------------------------------------------------------------------

    /**
     * If any playbook recommends IP-Co / royalty structure, build the node.
     */
    private function buildIpCoNode(array $intakeRows, array $playbookRecs, array &$blockers): ?array
    {
        foreach ($playbookRecs as $slug => $recs) {
            if (!is_array($recs)) {
                continue;
            }
            foreach ($recs as $pb) {
                if (!isset($pb['applies']) || !$pb['applies']) {
                    continue;
                }
                $pbId = $pb['playbook_id'] ?? '';
                if (strpos($pbId, 'ip_holding') !== false || strpos($pbId, 'royalty') !== false) {
                    return [
                        'id'    => 'ipco',
                        'type'  => 'ipco',
                        'layer' => 3,
                        'label' => 'MNMS IP Holdings LLC',
                        'meta'  => [
                            'jurisdiction' => $pb['ip_holding_jurisdiction'] ?? 'WY',
                            'form'         => 'llc',
                            'purpose'      => 'IP holding entity for royalty income shifting',
                            'note'         => 'Holds software, trademarks, trade secrets. Licenses to OpCos via intercompany royalty agreement.',
                        ],
                    ];
                }
            }
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function buildEntityMeta(array $row, string $entityType, string $jur): array
    {
        return [
            'jurisdiction'         => $jur,
            'form'                 => $entityType,
            'brand_slug'           => $row['brand_slug'] ?? '',
            'tier'                 => $row['tier'] ?? null,
            'liability_profile'    => $row['liability_profile'] ?? null,
            'annual_revenue_usd'   => isset($row['annual_revenue_usd']) ? (float)$row['annual_revenue_usd'] : null,
            'ebitda_usd'           => isset($row['ebitda_usd']) ? (float)$row['ebitda_usd'] : null,
            'aggression_tier'      => $row['aggression_tier'] ?? null,
            'decision_status'      => $row['decision_status'] ?? 'pending',
            'spawned_entity_id'    => $row['spawned_entity_id'] ?? null,
            'decided_sale_horizon' => $row['decided_sale_horizon'] ?? 'not_set',
        ];
    }

    private function fetchHoldcoMeta(array $portfolioCtx): array
    {
        return [
            'jurisdiction'      => self::HOLDCO_JUR,
            'form'              => self::HOLDCO_FORM,
            'established'       => '10+ years (S-Corp election already in place)',
            'tx_sos_current'    => $portfolioCtx['tx_sos_current'] ?? null,
            'franchise_tax_current' => $portfolioCtx['franchise_tax_current'] ?? null,
            'note'              => 'Parent HoldCo. DO NOT recommend Form 2553 — already S-Corp.',
        ];
    }

    private function isSeriesCell(array $row): bool
    {
        $slug = $row['brand_slug'] ?? '';
        // Known series cells from BrandPlacement knowledge
        return in_array($slug, ['usedcopierparts', 'usedprintersales', 'canonparts'], true);
    }

    private function seriesParentId(array $row): string
    {
        // All known series cells belong under PrintIt Series LLC
        return 'printit';
    }

    private function hasIpExposure(array $row): bool
    {
        $ipValue = (float)($row['ip_asset_value_usd'] ?? 0);
        $slug    = $row['brand_slug'] ?? '';
        // VoltOps has software IP; any brand with IP asset value > 0
        return $ipValue > 0 || $slug === 'voltops';
    }

    private function royaltyPct(array $row): float
    {
        // Standard arm's-length royalty: 3–7% of revenue for software/brand IP
        // Use 5% as default defensible rate — consistent with BrandPlacement rationale
        return 5.0;
    }

    private function mgmtFeePct(array $row, array $pbRecs): float
    {
        // Check playbook recs for explicit mgmt fee recommendation
        foreach ($pbRecs as $pb) {
            if (!isset($pb['applies']) || !$pb['applies']) {
                continue;
            }
            if (!empty($pb['mgmt_fee_pct'])) {
                return (float)$pb['mgmt_fee_pct'];
            }
        }
        // Default: 10% for T3+ brands with own entities; 0 for DBA-only
        $tier       = $row['tier'] ?? 'T1';
        $entityType = $row['decided_entity_type'] ?? 'keep_dba';
        if ($entityType !== 'keep_dba' && in_array($tier, ['T3', 'T4', 'T5'], true)) {
            return 10.0;
        }
        return 0.0;
    }

    /**
     * Get BrandPlacement recommendation for a row (lazy-loads class if needed).
     * Returns empty array on failure — never throws.
     */
    private function getBrandPlacementRec(array $row, array &$blockers): array
    {
        $intakeId = $row['id'] ?? null;
        if (!$intakeId) {
            return [];
        }
        try {
            if (!class_exists('BrandPlacement')) {
                $bpPath = dirname(__DIR__) . '/BrandPlacement.php';
                if (file_exists($bpPath)) {
                    require_once $bpPath;
                }
            }
            if (!class_exists('IntakeRepo')) {
                $irPath = dirname(__DIR__) . '/IntakeRepo.php';
                if (file_exists($irPath)) {
                    require_once $irPath;
                }
            }
            if (!class_exists('StateMatrix')) {
                $smPath = dirname(__DIR__) . '/StateMatrix.php';
                if (file_exists($smPath)) {
                    require_once $smPath;
                }
            }
            if (!class_exists('TrustBuilder')) {
                $tbPath = dirname(__DIR__) . '/TrustBuilder.php';
                if (file_exists($tbPath)) {
                    require_once $tbPath;
                }
            }
            if (class_exists('BrandPlacement')) {
                return \BrandPlacement::analyze($this->tenantId, (int)$intakeId);
            }
        } catch (\Throwable $e) {
            $slug = $row['brand_slug'] ?? ('id#' . $intakeId);
            $blockers[] = [
                'type'    => 'brand_placement_error',
                'brand'   => $slug,
                'message' => "BrandPlacement::analyze failed for '{$slug}': " . $e->getMessage(),
                'queue'   => null,
            ];
        }
        return [];
    }
}
