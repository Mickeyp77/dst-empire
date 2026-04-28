<?php
/**
 * DST Empire — Portfolio Analysis Engine for Entity Formation
 * Copyright (c) 2026 MNMS LLC
 * Licensed under the MIT License — see LICENSE in the project root.
 */

/**
 * BrandPlacement — rule-based decision engine for per-brand entity-structure recommendations.
 * Requires IntakeRepo, StateMatrix, TrustBuilder to be loaded first.
 */
class BrandPlacement {

    /**
     * Analyze a single brand intake record and produce a structured recommendation.
     * Returns {brand, current, recommendation{jurisdiction,entity_type,parent_kind,
     * trust_wrapper,sale_horizon}, rationale_md, alternatives[], cost_breakdown{first_year,annual_ongoing},
     * blast_radius_notes}.
     */
    public static function analyze(int $tenantId, int $intakeId): array {
        $intake = IntakeRepo::get($tenantId, $intakeId);
        if (!$intake) {
            throw new \RuntimeException("BrandPlacement::analyze — intake #{$intakeId} not found for tenant {$tenantId}");
        }

        $tier     = $intake['tier'];
        $liab     = $intake['liability_profile'];
        $slug     = $intake['brand_slug'];
        $name     = $intake['brand_name'];

        // --- Jurisdiction selection ---
        $jurisdiction = self::pickJurisdiction($intake);

        // --- Entity type selection (tier tie-break logic) ---
        $entityType = self::pickEntityType($intake);

        // --- Parent kind ---
        $parentKind = self::pickParentKind($intake, $entityType);

        // --- Trust wrapper (simplified: 0 estimated assets = no trust for now) ---
        $trustRec     = TrustBuilder::recommendTrust(0, $liab, $intake['decided_sale_horizon'] ?? 'never');
        $trustWrapper = 'none';
        if (in_array($liab, ['med_high','high'], true)) {
            // Flag potential DAPT need — asset value unknown at intake stage
            $trustWrapper = 'none'; // Can't commit without asset $ — flagged in rationale
        }

        // --- Costs ---
        $stateRow = StateMatrix::get($jurisdiction) ?: [];
        $formFee  = (float)($stateRow['formation_fee'] ?? 0);
        $annFee   = (float)($stateRow['annual_fee'] ?? 0);
        $raCost   = 150.0; // typical commercial RA
        $einCost  = 0.0;
        // Franchise tax estimate
        $ftNote   = $stateRow['franchise_tax'] ?? '';
        $ftCost   = ($jurisdiction === 'DE') ? 300.0 : 0.0;

        $firstYear   = $formFee + $raCost + $einCost;
        $annualOngoing = $annFee + $raCost + $ftCost;

        // --- Rationale markdown ---
        $rationale = self::buildRationale($intake, $jurisdiction, $entityType, $parentKind, $trustWrapper, $stateRow);

        // --- Alternatives ---
        $alternatives = self::buildAlternatives($intake, $jurisdiction, $entityType);

        // --- Blast radius ---
        $blastRadius = self::buildBlastRadius($intake);

        return [
            'brand'   => ['id' => $intakeId, 'slug' => $slug, 'name' => $name, 'tier' => $tier],
            'current' => [
                'status'       => $intake['current_status'],
                'legal_owner'  => $intake['current_legal_owner'],
                'liability'    => $liab,
            ],
            'recommendation' => [
                'jurisdiction' => $jurisdiction,
                'entity_type'  => $entityType,
                'parent_kind'  => $parentKind,
                'trust_wrapper' => $trustWrapper,
                'sale_horizon' => $intake['decided_sale_horizon'] ?? 'not_set',
            ],
            'rationale_md'  => $rationale,
            'alternatives'  => $alternatives,
            'cost_breakdown' => [
                'first_year'     => $firstYear,
                'annual_ongoing' => $annualOngoing,
                'notes'          => $ftNote ?: 'No state income tax.',
            ],
            'blast_radius_notes' => $blastRadius,
        ];
    }

    /** Run analyze() for every intake row for a tenant. Returns array keyed by brand_slug. */
    public static function bulkAnalyze(int $tenantId): array {
        $intakes = IntakeRepo::list($tenantId);
        $results = [];
        foreach ($intakes as $intake) {
            try {
                $results[$intake['brand_slug']] = self::analyze($tenantId, (int)$intake['id']);
            } catch (\Throwable $e) {
                $results[$intake['brand_slug']] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Generate a plain-English markdown narrative explaining the full decision path
     * for a brand profile array (does not require a saved intake row).
     */
    public static function decisionTreeNarrative(array $brandProfile): string {
        $tier = $brandProfile['tier'] ?? 'T4';
        $liab = $brandProfile['liability_profile'] ?? 'low';
        $sale = $brandProfile['sale_horizon'] ?? 'never';
        $name = $brandProfile['brand_name'] ?? 'this brand';

        $md  = "## Decision Tree Narrative — {$name}\n\n";
        $md .= "**Tier:** {$tier} | **Liability:** {$liab} | **Sale horizon:** {$sale}\n\n";

        $md .= "### Step 1 — Should this be a separate entity?\n";
        if ($tier === 'T1') {
            $md .= "T1 = core operating asset. YES — warrants its own entity for liability isolation and IP separation.\n\n";
        } elseif ($tier === 'T2') {
            $md .= "T2 = established revenue stream. YES — separate LLC unless cost > 3x annual net revenue.\n\n";
        } elseif ($tier === 'T3') {
            $md .= "T3 = asset-holding or recurring revenue. LIKELY YES — especially if asset liability > \$50k.\n\n";
        } elseif ($tier === 'T4') {
            $md .= "T4 = SEO/service tail. Evaluate: if contract liability exists → LLC; if pure SEO → DBA of MNMS LLC saves \$200–\$500/yr.\n\n";
        } else {
            $md .= "T5 = personal/internal. NO — keep under MNMS LLC or as personal activity.\n\n";
        }

        $md .= "### Step 2 — Jurisdiction selection\n";
        $md .= "- Home state TX: simplest ops, franchise tax (PIR even below threshold), moderate charging-order.\n";
        $md .= "- WY: best for anonymity + charging-order + low cost (\$100 + \$60/yr). No VC-friendliness.\n";
        $md .= "- DE: required if VC round or institutional sale target. \$300/yr franchise tax worth it for Chancery.\n";
        $md .= "- NV: strongest DAPT statute. Use when liability profile = high OR high-value assets.\n";
        $md .= "- SD: Dynasty Trust + DAPT stack. Use when multi-generational wealth transfer is the goal.\n\n";

        $md .= "### Step 3 — Entity type\n";
        $md .= "- LLC: default for ops isolation + pass-through + charging-order.\n";
        $md .= "- C-Corp (DE): only if VC round or institutional buyer target in horizon.\n";
        $md .= "- Series LLC cell: only if parent Series LLC already filed (TX/DE/NV). Single filing covers subs.\n";
        $md .= "- DBA: T4/T5 with zero contract liability and no sale plan. Cheapest.\n\n";

        $md .= "### Step 4 — Trust wrapper\n";
        $assetNote = "Threshold \$300k (WY DAPT) / \$500k (NV/SD DAPT) / \$1M (Dynasty SD) / \$2M (Bridge Trust).";
        if (in_array($liab, ['med_high','high'], true)) {
            $md .= "**Liability profile = {$liab}.** DAPT strongly recommended once assets cross \$300k–\$500k. {$assetNote}\n\n";
        } else {
            $md .= "Liability profile = {$liab}. Trust layer optional until assets or creditor threat materializes. {$assetNote}\n\n";
        }

        $md .= "### Step 5 — Parent linkage\n";
        if ($tier === 'T1' || $tier === 'T5') {
            $md .= "Keep under MNMS LLC (HoldCo) or as MNMS LLC itself — no separate parent needed.\n";
        } elseif ($sale === '1y' || $sale === 'active_sale') {
            $md .= "**Sale-ready clean-up required:** entity should be standalone (not owned by MNMS LLC directly) or buyer will demand ownership restructure. Consider holding LLC intermediary.\n";
        } else {
            $md .= "Own via MNMS LLC (HoldCo) for pass-through + Sabrina majority ownership continuity.\n";
        }

        return $md;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** Pick best jurisdiction given intake row fields. */
    private static function pickJurisdiction(array $intake): string {
        // If already decided, honor it
        if (!empty($intake['decided_jurisdiction'])) {
            return $intake['decided_jurisdiction'];
        }

        $tier = $intake['tier'];
        $liab = $intake['liability_profile'];
        $sale = $intake['decided_sale_horizon'] ?? 'never';

        // VC / active-sale path → DE
        if (in_array($sale, ['1y','active_sale'], true)) return 'DE';
        // High liability → NV (strongest DAPT)
        if ($liab === 'high') return 'NV';
        // T3 asset-holding + med liability → WY (charging-order + cheap)
        if ($tier === 'T3' && in_array($liab, ['medium','med_high'], true)) return 'WY';
        // T1 operational core → TX (home state)
        if ($tier === 'T1') return 'TX';
        // Default → TX (simplest for home-state ops)
        return 'TX';
    }

    /** Pick entity type using tier tie-break logic. */
    private static function pickEntityType(array $intake): string {
        if (!empty($intake['decided_entity_type'])) {
            return $intake['decided_entity_type'];
        }

        $tier = $intake['tier'];
        $liab = $intake['liability_profile'];
        $sale = $intake['decided_sale_horizon'] ?? 'never';
        $slug = $intake['brand_slug'];

        // T2 voltops → C-Corp DE path
        if ($slug === 'voltops') return 'c_corp';
        // Active-sale T2 → C-Corp candidate
        if ($tier === 'T2' && in_array($sale, ['1y','active_sale'], true)) return 'c_corp';
        // T3 = asset-holding LLC
        if ($tier === 'T3') return 'llc';
        // T4 low-liability → DBA (keep under MNMS)
        if ($tier === 'T4' && in_array($liab, ['low','low_med'], true)) return 'keep_dba';
        // T4 medium+ liability (payperprint, freeprinter) → LLC
        if ($tier === 'T4' && in_array($liab, ['medium','med_high','high'], true)) return 'llc';
        // T5 = personal / internal → keep as-is
        if ($tier === 'T5') return 'keep_dba';
        // T1 = LLC or existing S-Corp, no change
        if ($tier === 'T1') return 'llc';

        return 'llc';
    }

    /** Pick parent kind for the recommendation. */
    private static function pickParentKind(array $intake, string $entityType): string {
        if (!empty($intake['decided_parent_kind'])) {
            return $intake['decided_parent_kind'];
        }
        if ($entityType === 'keep_dba') return 'mnms_llc';
        if ($intake['tier'] === 'T1') return 'mnms_llc';
        if ($intake['tier'] === 'T5') return 'mnms_llc';
        // Sale-active → standalone preferred
        if (in_array($intake['decided_sale_horizon'] ?? '', ['1y','active_sale'], true)) {
            return 'holdco_llc';
        }
        return 'mnms_llc';
    }

    /** Build rationale markdown for the recommendation. */
    private static function buildRationale(
        array  $intake,
        string $jurisdiction,
        string $entityType,
        string $parentKind,
        string $trustWrapper,
        array  $stateRow
    ): string {
        $name = htmlspecialchars($intake['brand_name']);
        $tier = $intake['tier'];
        $liab = $intake['liability_profile'];
        $sale = $intake['decided_sale_horizon'] ?? 'not_set';

        $stateName = $stateRow['name'] ?? $jurisdiction;
        $formFee   = $stateRow['formation_fee'] ?? 0;
        $annFee    = $stateRow['annual_fee'] ?? 0;

        $md  = "## {$name} — Entity Structure Recommendation\n\n";
        $md .= "**Tier {$tier}** | Liability: `{$liab}` | Sale horizon: `{$sale}`\n\n";
        $md .= "### Recommended: {$entityType} in {$stateName}\n\n";

        // Tier-specific reasoning
        $tierReasons = [
            'T1' => "T1 = core operating asset. Entity separation essential for IP isolation, liability firewall, and future fundraise path.",
            'T2' => "T2 = established revenue stream. Separate entity justified — liability isolation + clean books for eventual sale diligence.",
            'T3' => "T3 = asset-holding or recurring-revenue brand. LLC provides charging-order protection on leased/rented assets.",
            'T4' => "T4 = SEO/service tail. " . ($entityType === 'keep_dba' ? "Low liability + low revenue → DBA saves \$200–\$500/yr over separate LLC." : "Contract liability present → LLC justified despite T4 tier."),
            'T5' => "T5 = personal/internal tooling. No separation warranted — keep under MNMS LLC.",
        ];
        $md .= ($tierReasons[$tier] ?? '') . "\n\n";

        $md .= "### Jurisdiction Rationale ({$stateName})\n";
        $md .= "Formation fee: \${$formFee} | Annual: \${$annFee}/yr\n\n";
        if (!empty($stateRow['notes_md'])) {
            $md .= $stateRow['notes_md'] . "\n\n";
        }

        $md .= "### Parent Linkage\n";
        $parentLabels = [
            'mnms_llc'    => "Owned by MNMS LLC (HoldCo, S-Corp). Pass-through income, Sabrina majority-ownership continuity.",
            'holdco_llc'  => "Owned by a dedicated HoldCo LLC (intermediary). Cleaner for separate-entity sale without disturbing MNMS LLC cap table.",
            'dapt'        => "Owned via DAPT — strong asset-protection wrapper for high-liability brands.",
            'mickey_personal' => "Mickey personal ownership (sole prop / personal brand).",
            'mixed'       => "Mixed ownership structure — verify with Mickey before filing.",
        ];
        $md .= ($parentLabels[$parentKind] ?? "Parent: {$parentKind}") . "\n\n";

        if ($trustWrapper !== 'none') {
            $md .= "### Trust Wrapper: {$trustWrapper}\n";
            $trustSummary = TrustBuilder::summaryFor($trustWrapper);
            if (!empty($trustSummary['when_to_consider_md'])) {
                $md .= $trustSummary['when_to_consider_md'] . "\n\n";
            }
        }

        // Trademark warning
        if (in_array($intake['brand_slug'], ['canonservice','canonparts'], true)) {
            $md .= "### ⚠️ Trademark Risk\n";
            $md .= "\"Canon\" is a registered trademark of Canon Inc. Do NOT use Canon in the legal entity name. ";
            $md .= "Rename to trademark-clean alternative (e.g. \"Authorized Imaging Specialists\") before formation.\n\n";
        }

        return $md;
    }

    /** Build alternative paths for the recommendation. */
    private static function buildAlternatives(array $intake, string $primaryJurisdiction, string $primaryEntity): array {
        $alts = [];

        if ($primaryEntity !== 'keep_dba' && in_array($intake['liability_profile'], ['low','low_med'], true)) {
            $alts[] = [
                'label'       => 'DBA under MNMS LLC',
                'tradeoff'    => 'Saves formation cost entirely but no liability firewall. Acceptable only if brand never signs contracts or employs staff.',
                'annual_cost' => '$0 (Tarrant County DBA ~$20 one-time)',
            ];
        }

        if ($primaryJurisdiction !== 'WY') {
            $alts[] = [
                'label'       => 'WY LLC (anonymous)',
                'tradeoff'    => 'Cheapest jurisdictions ($100 + $60/yr), anonymous member rolls, strong charging-order. Downside: not VC-friendly if sale is goal.',
                'annual_cost' => '$60/yr + $150 RA',
            ];
        }

        if ($primaryEntity !== 'c_corp' && $intake['tier'] === 'T2') {
            $alts[] = [
                'label'       => 'DE C-Corp',
                'tradeoff'    => 'Opens VC + institutional-buyer path. Requires $300/yr DE franchise tax, separate payroll/officer structure. Convert from LLC later via merger if revenue justifies.',
                'annual_cost' => '$300 franchise + $150 RA',
            ];
        }

        return $alts;
    }

    /** Build blast-radius note for the intake record. */
    private static function buildBlastRadius(array $intake): string {
        $notes = [];
        if ($intake['current_status'] === 'operating' || $intake['current_status'] === 'filed') {
            $notes[] = "Brand is currently operating under MNMS LLC. Forming a new entity requires: (1) assignment of contracts from MNMS LLC to new entity, (2) new EIN + bank account, (3) QBO file for separate books (or class-track under MNMS QBO — check with Sabrina).";
        }
        if ($intake['brand_slug'] === 'voltops') {
            $notes[] = "VoltOps IP lives in mnmsos-saas codebase. Assignment agreement required before forming separate C-Corp. Sabrina majority-ownership of MNMS LLC creates valuation complexity — get IP assignment valued cleanly.";
        }
        if (in_array($intake['brand_slug'], ['usedcopierparts','usedprintersales','canonparts'], true)) {
            $notes[] = "Series LLC cell candidate — requires parent PrintIt LLC to be formed first as a Series LLC in TX/DE. Single filing fee covers parent + cells.";
        }
        if (empty($notes)) {
            $notes[] = "Currently DBA only. No active contracts or employees known. Blast radius: low — formation is additive, not migrative.";
        }
        return implode(' ', $notes);
    }
}
