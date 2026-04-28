<?php
/**
 * PlaybookRegistry — Central registry for all DST Empire tax/structuring playbooks.
 *
 * Usage:
 *   $registry = PlaybookRegistry::getInstance();
 *   $all      = $registry->getAll();
 *   $pb       = $registry->getById('scorp_election');
 *   $results  = $registry->runAll($intakeRow, $portfolioContextRow);
 *   $results  = $registry->runAllSorted($intakeRow, $portfolioContextRow);
 *
 * runAll() returns an array keyed by playbook ID; each value is the
 * evaluate() output array. Non-applicable playbooks are included with
 * applies=false so the UI can show "not applicable" state.
 *
 * runAllSorted() returns the same but sorted by applicability_score DESC,
 * then estimated_savings_y1_usd DESC, useful for dashboard ranking.
 */

namespace Mnmsos\Empire\Playbooks;

class PlaybookRegistry
{
    /** @var AbstractPlaybook[] */
    private array $playbooks = [];

    private static ?PlaybookRegistry $instance = null;

    private function __construct()
    {
        $this->register(new SCorpElectionPlaybook());
        $this->register(new QSBS1202Playbook());
        $this->register(new QBI199APlaybook());
        $this->register(new RDCredit41Playbook());
        $this->register(new IPCoSeparationPlaybook());
        $this->register(new CaptiveInsurance831bPlaybook());
        $this->register(new MgmtFeeTransferPricingPlaybook());
        $this->register(new ChargingOrderProtectionPlaybook());
        $this->register(new FLPValuationDiscountPlaybook());
        $this->register(new DAPTDomesticAssetPlaybook());
        $this->register(new CostSegregationPlaybook());
        $this->register(new Solo401kMaxPlaybook());
    }

    /** Singleton accessor. */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Register a playbook. Overwrites if same ID exists. */
    public function register(AbstractPlaybook $playbook): void
    {
        $this->playbooks[$playbook->getId()] = $playbook;
    }

    /**
     * Return all registered playbooks.
     * @return AbstractPlaybook[]
     */
    public function getAll(): array
    {
        return array_values($this->playbooks);
    }

    /**
     * Return a single playbook by ID, or null if not found.
     */
    public function getById(string $id): ?AbstractPlaybook
    {
        return $this->playbooks[$id] ?? null;
    }

    /**
     * Return all playbooks in a given category.
     * @return AbstractPlaybook[]
     */
    public function getByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->playbooks,
            static fn(AbstractPlaybook $pb) => $pb->getCategory() === $category
        ));
    }

    /**
     * Return all playbooks at or below a given aggression tier.
     * @return AbstractPlaybook[]
     */
    public function getByMaxTier(string $maxTier): array
    {
        $order = ['conservative' => 0, 'growth' => 1, 'aggressive' => 2];
        $max   = $order[$maxTier] ?? 2;
        return array_values(array_filter(
            $this->playbooks,
            static fn(AbstractPlaybook $pb) => ($order[$pb->getAggressionTier()] ?? 0) <= $max
        ));
    }

    /**
     * Run all playbooks against a single intake row + portfolio context.
     *
     * @param array $intake           empire_brand_intake row
     * @param array $portfolioContext empire_portfolio_context row (may be empty [])
     * @return array<string, array>   keyed by playbook ID
     */
    public function runAll(array $intake, array $portfolioContext): array
    {
        $results = [];
        foreach ($this->playbooks as $id => $playbook) {
            try {
                $results[$id] = $playbook->evaluate($intake, $portfolioContext);
                // Inject meta fields for convenience
                $results[$id]['_playbook_id']       = $playbook->getId();
                $results[$id]['_playbook_name']      = $playbook->getName();
                $results[$id]['_code_section']       = $playbook->getCodeSection();
                $results[$id]['_aggression_tier']    = $playbook->getAggressionTier();
                $results[$id]['_category']           = $playbook->getCategory();
            } catch (\Throwable $e) {
                $results[$id] = [
                    'applies'              => false,
                    'applicability_score'  => 0,
                    '_playbook_id'         => $id,
                    '_playbook_name'       => $playbook->getName(),
                    '_error'               => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /**
     * Run all playbooks and return results sorted by applicability_score DESC,
     * then estimated_savings_y1_usd DESC. Non-applicable (applies=false) sink
     * to the bottom.
     *
     * @param array $intake
     * @param array $portfolioContext
     * @return array[] — flat array, not keyed by ID
     */
    public function runAllSorted(array $intake, array $portfolioContext): array
    {
        $results = array_values($this->runAll($intake, $portfolioContext));

        usort($results, static function (array $a, array $b): int {
            // Applicable results float to top
            $aApplies = (int)($a['applies'] ?? 0);
            $bApplies = (int)($b['applies'] ?? 0);
            if ($aApplies !== $bApplies) {
                return $bApplies - $aApplies;
            }
            // Then by applicability score
            $scoreA = (int)($a['applicability_score'] ?? 0);
            $scoreB = (int)($b['applicability_score'] ?? 0);
            if ($scoreA !== $scoreB) {
                return $scoreB - $scoreA;
            }
            // Then by Y1 savings
            $savA = (float)($a['estimated_savings_y1_usd'] ?? 0.0);
            $savB = (float)($b['estimated_savings_y1_usd'] ?? 0.0);
            return $savB <=> $savA;
        });

        return $results;
    }

    /**
     * Quick summary of all playbooks — ID, name, tier, category, applies
     * — without running full evaluation. Useful for the UI filter panel.
     *
     * @param array $intake
     * @param array $portfolioContext
     * @return array[]
     */
    public function summary(array $intake, array $portfolioContext): array
    {
        $out = [];
        foreach ($this->playbooks as $playbook) {
            $applies = false;
            try {
                $applies = $playbook->applies($intake, $portfolioContext);
            } catch (\Throwable $_) {
                // Silently mark as not applicable on error
            }
            $out[] = [
                'id'             => $playbook->getId(),
                'name'           => $playbook->getName(),
                'code_section'   => $playbook->getCodeSection(),
                'aggression_tier'=> $playbook->getAggressionTier(),
                'category'       => $playbook->getCategory(),
                'applies'        => $applies,
            ];
        }
        return $out;
    }

    /**
     * Compute total estimated Y1 and 5Y savings across all applicable playbooks.
     * Useful for the "total opportunity" dashboard card.
     */
    public function totalOpportunity(array $intake, array $portfolioContext): array
    {
        $totalY1 = 0.0;
        $total5y = 0.0;
        $count   = 0;
        foreach ($this->runAll($intake, $portfolioContext) as $result) {
            if (!($result['applies'] ?? false)) {
                continue;
            }
            $totalY1 += (float)($result['estimated_savings_y1_usd'] ?? 0.0);
            $total5y  += (float)($result['estimated_savings_5y_usd'] ?? 0.0);
            $count++;
        }
        return [
            'applicable_playbook_count'  => $count,
            'total_savings_y1_usd'       => round($totalY1, 2),
            'total_savings_5y_usd'       => round($total5y, 2),
        ];
    }
}
