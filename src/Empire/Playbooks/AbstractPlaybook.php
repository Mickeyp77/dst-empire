<?php
/**
 * AbstractPlaybook — base class for all DST Empire tax/structuring playbooks.
 *
 * Every playbook is a deterministic rule engine — NO LLM calls here.
 * LLM augmentation lives in Advisor.php. These classes produce defensible
 * $ estimates and citation-backed rationale from intake data alone.
 *
 * Aggression tier guard:
 *   conservative  → only 'conservative' playbooks apply
 *   growth        → 'conservative' + 'growth' apply
 *   aggressive    → all three tiers apply
 *
 * Namespace: Mnmsos\Empire\Playbooks
 */

namespace Mnmsos\Empire\Playbooks;

abstract class AbstractPlaybook
{
    // ------------------------------------------------------------------
    // Identity methods — must be overridden
    // ------------------------------------------------------------------

    /** Machine identifier, e.g. 'scorp_election'. */
    abstract public function getId(): string;

    /** Human-readable name shown in the UI. */
    abstract public function getName(): string;

    /** Primary IRC section(s), e.g. '§1361/§1362'. */
    abstract public function getCodeSection(): string;

    /**
     * Minimum aggression tier required for this playbook to fire.
     * 'conservative' | 'growth' | 'aggressive'
     */
    abstract public function getAggressionTier(): string;

    /**
     * Broad category for grouping/filtering.
     * 'tax' | 'liability' | 'sale' | 'estate' | 'intercompany'
     */
    abstract public function getCategory(): string;

    // ------------------------------------------------------------------
    // Evaluation methods — must be overridden
    // ------------------------------------------------------------------

    /**
     * Quick gate — true if this playbook is worth evaluating for this intake.
     * Should NOT compute savings — just check preconditions cheaply.
     *
     * @param array $intake           Row from empire_brand_intake (all columns).
     * @param array $portfolioContext Row from empire_portfolio_context.
     */
    abstract public function applies(array $intake, array $portfolioContext): bool;

    /**
     * Full evaluation. Returns a structured result array (see below).
     * MUST honour aggression tier gate (call tierAllowed() first).
     *
     * Return shape:
     * [
     *   'applies'                   => bool,
     *   'applicability_score'       => int,       // 0–100
     *   'estimated_savings_y1_usd'  => float,
     *   'estimated_savings_5y_usd'  => float,
     *   'estimated_setup_cost_usd'  => float,
     *   'estimated_ongoing_cost_usd'=> float,     // per year
     *   'risk_level'                => 'low'|'medium'|'high',
     *   'audit_visibility'          => 'low'|'medium'|'high',
     *   'prerequisites_md'          => string,
     *   'rationale_md'              => string,
     *   'gotchas_md'                => string,
     *   'citations'                 => string[],
     *   'docs_required'             => string[],
     *   'next_actions'              => string[],
     * ]
     *
     * @param array $intake           Row from empire_brand_intake.
     * @param array $portfolioContext Row from empire_portfolio_context.
     */
    abstract public function evaluate(array $intake, array $portfolioContext): array;

    // ------------------------------------------------------------------
    // Shared helpers available to all concrete playbooks
    // ------------------------------------------------------------------

    /**
     * Returns true when the intake's aggression_tier satisfies this
     * playbook's minimum tier.
     *
     * Hierarchy: conservative < growth < aggressive
     */
    protected function tierAllowed(array $intake): bool
    {
        $order = ['conservative' => 0, 'growth' => 1, 'aggressive' => 2];
        $intakeTier   = $order[$intake['aggression_tier'] ?? 'growth'] ?? 1;
        $requiredTier = $order[$this->getAggressionTier()] ?? 0;
        return $intakeTier >= $requiredTier;
    }

    /**
     * Return a zeroed-out "does not apply" result, optionally with a
     * short rationale explaining why.
     */
    protected function notApplicable(string $reason = ''): array
    {
        return [
            'applies'                    => false,
            'applicability_score'        => 0,
            'estimated_savings_y1_usd'   => 0.0,
            'estimated_savings_5y_usd'   => 0.0,
            'estimated_setup_cost_usd'   => 0.0,
            'estimated_ongoing_cost_usd' => 0.0,
            'risk_level'                 => 'low',
            'audit_visibility'           => 'low',
            'prerequisites_md'           => '',
            'rationale_md'               => $reason,
            'gotchas_md'                 => '',
            'citations'                  => [],
            'docs_required'              => [],
            'next_actions'               => [],
        ];
    }

    /**
     * Safely cast a nullable column to float (0.0 if NULL/empty).
     */
    protected function f(mixed $val): float
    {
        return (float)($val ?? 0.0);
    }

    /**
     * Safely cast to int.
     */
    protected function i(mixed $val): int
    {
        return (int)($val ?? 0);
    }

    /**
     * Compute net present value of a flat annual cash flow over N years
     * at a given discount rate (default 8 % — conservative cost of capital).
     */
    protected function npv(float $annualCashFlow, int $years = 5, float $rate = 0.08): float
    {
        if ($rate <= 0) {
            return $annualCashFlow * $years;
        }
        $pv = 0.0;
        for ($t = 1; $t <= $years; $t++) {
            $pv += $annualCashFlow / (1 + $rate) ** $t;
        }
        return round($pv, 2);
    }

    /**
     * Clamp an integer to [0, 100].
     */
    protected function score(int $raw): int
    {
        return max(0, min(100, $raw));
    }
}
