<?php
/**
 * src/Empire/Compliance/RecurrenceCalculator.php
 *
 * Computes next due dates for compliance tasks based on recurrence rules
 * and state-specific schedules.
 *
 * Namespace: Mnmsos\Empire\Compliance
 */

namespace Mnmsos\Empire\Compliance;

class RecurrenceCalculator
{
    // ─────────────────────────────────────────────────────────────────────
    // State-specific annual due dates: [month, day]
    // Month is 1-indexed; day is literal calendar day.
    // ─────────────────────────────────────────────────────────────────────

    /** @var array<string,array{month:int,day:int}> Annual report due by state */
    private static array $ANNUAL_REPORT_DATES = [
        'TX' => ['month' => 5,  'day' => 15],   // Texas: May 15
        'DE' => ['month' => 3,  'day' => 1],    // Delaware: March 1
        'WY' => ['month' => 0,  'day' => 1],    // Wyoming: 1st of anniversary month (0=dynamic)
        'NV' => ['month' => 0,  'day' => 31],   // Nevada: last day of anniversary month (dynamic)
        'SD' => ['month' => 0,  'day' => 1],    // South Dakota: anniversary month
        'FL' => ['month' => 5,  'day' => 1],    // Florida: May 1
        'CA' => ['month' => 0,  'day' => 15],   // California: varies — anniversary based
        'WA' => ['month' => 0,  'day' => 1],    // Washington: anniversary month
    ];

    /** @var array<string,array{month:int,day:int}> Franchise tax due by state */
    private static array $FRANCHISE_TAX_DATES = [
        'TX' => ['month' => 5,  'day' => 15],   // Texas franchise tax: May 15
        'DE' => ['month' => 3,  'day' => 1],    // Delaware franchise tax: March 1 (report); tax due June 1
        'CA' => ['month' => 4,  'day' => 15],   // California: April 15
        'NY' => ['month' => 3,  'day' => 15],   // New York: March 15
    ];

    /** @var array<string,array{month:int,day:int}> Federal tax due dates by entity type */
    private static array $FEDERAL_TAX_DATES = [
        '1120-S'    => ['month' => 3,  'day' => 15],  // S-Corp: March 15 (ext: Sep 15)
        '1120'      => ['month' => 4,  'day' => 15],  // C-Corp: April 15 (ext: Oct 15)
        '1040'      => ['month' => 4,  'day' => 15],  // Sole/Partnership: April 15 (ext: Oct 15)
        '1065'      => ['month' => 3,  'day' => 15],  // Partnership: March 15 (ext: Sep 15)
        '990'       => ['month' => 5,  'day' => 15],  // 501(c)(3): May 15 (4.5 mo after fiscal yr)
        '1041'      => ['month' => 4,  'day' => 15],  // Trust/Estate: April 15
    ];

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Compute the next occurrence from a base date + recurrence rule.
     *
     * @param  \DateTime $base        Reference date (e.g. last due date, or today)
     * @param  string    $recurrence  'once'|'annual'|'quarterly'|'monthly'|'custom'
     * @return \DateTime|null  null for 'once' (no recurrence)
     */
    public static function nextOccurrence(\DateTime $base, string $recurrence): ?\DateTime
    {
        $next = clone $base;

        switch ($recurrence) {
            case 'annual':
                $next->modify('+1 year');
                return $next;

            case 'quarterly':
                $next->modify('+3 months');
                return $next;

            case 'monthly':
                $next->modify('+1 month');
                return $next;

            case 'custom':
                // Custom recurrence must be resolved externally; return +1 year as safe default
                $next->modify('+1 year');
                return $next;

            case 'once':
            default:
                return null;
        }
    }

    /**
     * Returns the next applicable due date for a given task type + jurisdiction.
     *
     * For anniversary-based states (WY, SD, NV, WA, CA when month=0):
     *   Uses $baseFormationDate to determine the anniversary month/day.
     *
     * Always returns a date in the future (advances year if already past for this year).
     *
     * @param  string    $taskType        e.g. 'annual_report', 'franchise_tax', 'federal_tax'
     * @param  string    $jurisdiction    Two-letter state code or 'federal'
     * @param  \DateTime $baseFormationDate  Entity formation date
     * @return \DateTime
     */
    public static function dueDateFor(
        string    $taskType,
        string    $jurisdiction,
        \DateTime $baseFormationDate
    ): \DateTime {
        $jurisdiction = strtoupper(trim($jurisdiction));
        $today        = new \DateTime('today');
        $year         = (int)$today->format('Y');

        switch ($taskType) {
            // ── Annual report ──────────────────────────────────────────
            case 'annual_report':
                return self::resolveAnnualDate(
                    self::$ANNUAL_REPORT_DATES,
                    $jurisdiction,
                    $baseFormationDate,
                    $today,
                    $year
                );

            // ── Franchise tax ──────────────────────────────────────────
            case 'franchise_tax':
                return self::resolveAnnualDate(
                    self::$FRANCHISE_TAX_DATES,
                    $jurisdiction,
                    $baseFormationDate,
                    $today,
                    $year
                );

            // ── Federal income tax ─────────────────────────────────────
            case 'federal_tax':
                // $jurisdiction carries form type (1120-S, 1040, 1065, 990)
                // Fall through to state_tax if not a federal form type key
                $formType = strtoupper($jurisdiction);
                if (isset(self::$FEDERAL_TAX_DATES[$formType])) {
                    $spec = self::$FEDERAL_TAX_DATES[$formType];
                    return self::buildDateAdvancing($spec['month'], $spec['day'], $today, $year);
                }
                // Default: April 15
                return self::buildDateAdvancing(4, 15, $today, $year);

            // ── State income tax ───────────────────────────────────────
            case 'state_tax':
                // Most states mirror federal deadline; TX uses May 15
                if ($jurisdiction === 'TX') {
                    return self::buildDateAdvancing(5, 15, $today, $year);
                }
                // CA: April 15; else mirror federal April 15
                return self::buildDateAdvancing(4, 15, $today, $year);

            // ── BOI update ─────────────────────────────────────────────
            case 'boi_update':
                // Initial BOI: 30 days from formation date
                $due = clone $baseFormationDate;
                $due->modify('+30 days');
                // If already past, return as-is (overdue — caller handles)
                return $due;

            // ── License renewal ────────────────────────────────────────
            case 'license_renewal':
                // Default: anniversary of formation date
                return self::nextAnniversary($baseFormationDate, $today);

            // ── Trust admin / annual accounting ────────────────────────
            case 'trust_admin':
                // Typically within 60 days of fiscal year end (Dec 31 → Mar 1)
                return self::buildDateAdvancing(3, 1, $today, $year);

            // ── §83(b) anniversary ─────────────────────────────────────
            case '83b_anniversary':
                return self::nextAnniversary($baseFormationDate, $today);

            // ── §1202 5-year clock ─────────────────────────────────────
            case '1202_clock':
                // Single event: 5 years from formation/grant date
                $due = clone $baseFormationDate;
                $due->modify('+5 years');
                return $due;

            // ── DAPT seasoning anniversary ─────────────────────────────
            case 'dapt_seasoning':
                // WY/SD: 2-year seasoning; reminder at 18 months + annual thereafter
                $due = clone $baseFormationDate;
                $due->modify('+18 months');
                if ($due < $today) {
                    return self::nextAnniversary($baseFormationDate, $today);
                }
                return $due;

            // ── §1031 exchange clock ───────────────────────────────────
            case '1031_clock':
                // 45-day ID + 180-day closing; tracked from formation date
                $due = clone $baseFormationDate;
                $due->modify('+45 days');
                return $due;

            // ── §199A recalc ───────────────────────────────────────────
            case '199a_recalc':
                // Annual, tied to tax filing — March 15 for pass-throughs
                return self::buildDateAdvancing(3, 15, $today, $year);

            // ── §531 accumulated earnings recheck ──────────────────────
            case '531_recheck':
                // Annual, before fiscal year end filing — March 15
                return self::buildDateAdvancing(3, 15, $today, $year);

            // ── PTET election ──────────────────────────────────────────
            case 'ptet_election':
                // Varies by state; default March 15 (before entity tax filing)
                return self::buildDateAdvancing(3, 15, $today, $year);

            // ── Insurance renewal ──────────────────────────────────────
            case 'insurance_renewal':
                return self::nextAnniversary($baseFormationDate, $today);

            // ── Trademark renewal ──────────────────────────────────────
            case 'tm_renewal':
                // USPTO Section 8/9: between 9-10 years from registration, then every 10 years
                $due = clone $baseFormationDate;
                $due->modify('+9 years');
                if ($due < $today) {
                    return self::nextAnniversary($baseFormationDate, $today);
                }
                return $due;

            // ── Captive insurance filing ───────────────────────────────
            case 'captive_filing':
                // Most captive states (VT, MT, HI): March 15 annual
                return self::buildDateAdvancing(3, 15, $today, $year);

            // ── FBAR (FinCEN 114) ──────────────────────────────────────
            case 'fbar':
                // April 15, auto-extension to Oct 15
                return self::buildDateAdvancing(4, 15, $today, $year);

            // ── Crummey letter ─────────────────────────────────────────
            case 'crummey_letter':
                // Annual, before year-end gift — November 1 deadline (30-day withdrawal window)
                return self::buildDateAdvancing(11, 1, $today, $year);

            // ── 1099 filings ───────────────────────────────────────────
            case '1099_filing':
                // January 31 for 1099-NEC, February 28 for others
                return self::buildDateAdvancing(1, 31, $today, $year);

            // ── Reasonable comp review ─────────────────────────────────
            case 'reasonable_comp_review':
                // Annual, before year-end — October 1 for S-Corp planning
                return self::buildDateAdvancing(10, 1, $today, $year);

            // ── Form 990 ───────────────────────────────────────────────
            case 'form_990':
                return self::buildDateAdvancing(5, 15, $today, $year);

            // ── Default: annual from formation ─────────────────────────
            default:
                return self::nextAnniversary($baseFormationDate, $today);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a date for month/day in the given year, then advance by 1 year
     * if that date is already past today.
     *
     * Handles leap-year edge case: Feb 29 in a non-leap year rolls to Mar 1.
     */
    private static function buildDateAdvancing(
        int $month,
        int $day,
        \DateTime $today,
        int $year
    ): \DateTime {
        $candidate = self::safeMakeDate($year, $month, $day);
        if ($candidate <= $today) {
            $candidate = self::safeMakeDate($year + 1, $month, $day);
        }
        return $candidate;
    }

    /**
     * Create a DateTime for year/month/day; if the day overflows (e.g. Feb 29
     * in a non-leap year), PHP's mktime rolls forward — we clamp to last valid
     * day of that month instead to stay in-month.
     */
    private static function safeMakeDate(int $year, int $month, int $day): \DateTime
    {
        // Last valid day of month
        $maxDay = (int)(new \DateTime("{$year}-{$month}-01"))->format('t');
        $day    = min($day, $maxDay);
        $dt     = \DateTime::createFromFormat('Y-n-j', "{$year}-{$month}-{$day}");
        return $dt ?: new \DateTime("{$year}-{$month}-{$maxDay}");
    }

    /**
     * Resolve annual due date from a lookup table, handling anniversary-based states.
     *
     * @param array<string,array{month:int,day:int}> $table
     */
    private static function resolveAnnualDate(
        array     $table,
        string    $jurisdiction,
        \DateTime $baseFormationDate,
        \DateTime $today,
        int       $year
    ): \DateTime {
        if (!isset($table[$jurisdiction])) {
            // Unknown state: default to anniversary of formation date
            return self::nextAnniversary($baseFormationDate, $today);
        }

        $spec  = $table[$jurisdiction];
        $month = $spec['month'];
        $day   = $spec['day'];

        if ($month === 0) {
            // Anniversary-based: use formation month
            $month = (int)$baseFormationDate->format('n');
            if ($day === 31) {
                // "Last day of anniversary month"
                $candidate = self::safeMakeDate($year, $month, 31);
                // safeMakeDate already clamps to last day
            } else {
                $candidate = self::safeMakeDate($year, $month, $day);
            }
            if ($candidate <= $today) {
                $candidate = self::safeMakeDate($year + 1, $month, $day);
            }
            return $candidate;
        }

        return self::buildDateAdvancing($month, $day, $today, $year);
    }

    /**
     * Returns the next anniversary of $base relative to $today.
     * If base's month/day already passed this year, returns next year.
     */
    private static function nextAnniversary(\DateTime $base, \DateTime $today): \DateTime
    {
        $month = (int)$base->format('n');
        $day   = (int)$base->format('j');
        $year  = (int)$today->format('Y');

        $candidate = self::safeMakeDate($year, $month, $day);
        if ($candidate <= $today) {
            $candidate = self::safeMakeDate($year + 1, $month, $day);
        }
        return $candidate;
    }
}
