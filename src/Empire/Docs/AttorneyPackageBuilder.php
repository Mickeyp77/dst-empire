<?php
/**
 * AttorneyPackageBuilder — orchestrates the full attorney-ready package
 * for a locked (or in-review) empire_brand_intake record.
 *
 * Output directory:
 *   storage/dstempire/packages/<tenant_id>/<intake_id>/<YmdHis>/
 *
 * Files generated:
 *   cover_memo.md + .docx + .pdf (if Pandoc available)
 *   atty_checklist.md
 *   engagement_letter.md
 *   atty_red_flags.md
 *   client_checklist.md
 *   filing_timeline.md
 *   Per template: <slug>.md + optional .docx + .pdf
 *   manifest.json
 *
 * Returns:
 *   ['package_dir', 'manifest_path', 'files' => [...], 'errors' => [...]]
 *
 * Design notes:
 *   - Works with decision_status = 'in_review' but adds a draft watermark
 *     to the cover memo. Only 'locked' status produces a "final" package.
 *   - Each call produces a new timestamped directory — old packages are
 *     preserved for audit. Regenerate = version+1 on all renders.
 *   - PandocConverter failure is non-fatal: .md always written, .docx/.pdf
 *     recorded as null in manifest.
 */

namespace Mnmsos\Empire\Docs;

use PDO;
use RuntimeException;

class AttorneyPackageBuilder
{
    /** Root storage path (absolute). Set as constant so it's easy to override. */
    private const STORAGE_ROOT = __DIR__ . '/../../../storage/dstempire/packages';

    private PDO $db;
    private int $tenantId;
    private TemplateRenderer  $renderer;
    private PandocConverter   $pandoc;

    // ── Template slugs that map to entity types + jurisdictions ──────────
    // category → [ entity_types ] → template slug patterns
    private const FORMATION_SLUG_MAP = [
        'TX' => [
            'llc'        => ['tx_llc_articles_205',   'tx_llc_oa_single_member'],
            'series_llc' => ['tx_llc_articles_205',   'tx_series_llc_parent_oa', 'tx_series_llc_cell_oa'],
            'scorp'      => ['tx_llc_articles_205',   'tx_llc_oa_single_member', 'irs_form_2553'],
            'ccorp'      => ['de_ccorp_articles',     'de_ccorp_bylaws'],   // DE C-Corp even for TX ops
        ],
        'DE' => [
            'llc'        => ['de_llc_oa'],
            'ccorp'      => ['de_ccorp_articles',     'de_ccorp_bylaws', 'de_ccorp_stock_subscription'],
            'series_llc' => ['de_series_llc_oa'],
            'scorp'      => ['de_ccorp_articles',     'irs_form_2553'],
        ],
        'WY' => [
            'llc'        => ['wy_llc_articles',       'wy_llc_oa_single_member'],
            'dapt'       => ['wy_dapt'],
        ],
        'NV' => [
            'llc'        => ['nv_llc_oa'],
            'dapt'       => ['nv_dapt'],
        ],
    ];

    /** Universal templates for every package regardless of jurisdiction/type. */
    private const UNIVERSAL_SLUGS = [
        'ip_assignment_founder_entity',
        'fincen_boir_template',
        'irs_form_ss4',
    ];

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
        $this->renderer = new TemplateRenderer($db, $tenantId);
        $this->pandoc   = new PandocConverter();
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Build the full attorney-ready package for an intake record.
     *
     * @param int $intakeId  empire_brand_intake.id
     * @return array {
     *   package_dir:   string        — absolute path to generated directory
     *   manifest_path: string        — absolute path to manifest.json
     *   files:         array[]       — per-file metadata (name, md_path, docx_path, pdf_path, hash)
     *   errors:        string[]      — non-fatal errors (missing templates, Pandoc failures, etc.)
     * }
     */
    public function buildForIntake(int $intakeId): array
    {
        $intake = $this->fetchIntake($intakeId);
        if ($intake === null) {
            throw new RuntimeException(
                "Intake #{$intakeId} not found for tenant {$this->tenantId}."
            );
        }

        // Warn but continue for in-review status — cover memo gets a DRAFT watermark
        $errors = [];
        if ($intake['decision_status'] === 'in_review') {
            $errors[] = 'Decision is not locked — package is a DRAFT. '
                . 'Lock the decision (IntakeRepo::lock()) for a final package.';
        } elseif ($intake['decision_status'] === 'draft') {
            $errors[] = 'Decision has not been started (status=draft). '
                . 'Package will be minimal.';
        }

        // ── Build package directory ───────────────────────────────────────
        $pkgDir = $this->makePackageDir($intakeId);
        $files  = [];

        // ── Fetch playbook results (from PortfolioSynthesizer if available) ─
        $playbookResults   = $this->fetchPlaybookResults($intakeId);
        $portfolioContext  = $this->fetchPortfolioContext();

        // ── 1. Cover memo ─────────────────────────────────────────────────
        $coverResult = $this->buildCoverMemo(
            $pkgDir, $intake, $portfolioContext, $playbookResults
        );
        $files[]  = $coverResult['file'];
        $errors   = array_merge($errors, $coverResult['errors']);

        // ── 2. Formation templates ────────────────────────────────────────
        $formationResults = $this->buildFormationDocs($pkgDir, $intake, $intakeId);
        foreach ($formationResults['files'] as $f) {
            $files[] = $f;
        }
        $errors = array_merge($errors, $formationResults['errors']);

        // ── 3. Attorney review checklist ──────────────────────────────────
        $files[] = $this->buildStaticDoc(
            $pkgDir, 'atty_checklist.md',
            $this->generateAttorneyChecklist($intake, $formationResults['slugs_rendered'])
        );

        // ── 4. Engagement letter template ─────────────────────────────────
        $files[] = $this->buildStaticDoc(
            $pkgDir, 'engagement_letter.md',
            $this->generateEngagementLetter($intake)
        );

        // ── 5. Red flags list ─────────────────────────────────────────────
        $files[] = $this->buildStaticDoc(
            $pkgDir, 'atty_red_flags.md',
            $this->generateRedFlagsList()
        );

        // ── 6. Client self-do checklist ───────────────────────────────────
        $files[] = $this->buildStaticDoc(
            $pkgDir, 'client_checklist.md',
            $this->generateClientChecklist($intake)
        );

        // ── 7. Filing timeline ────────────────────────────────────────────
        $files[] = $this->buildStaticDoc(
            $pkgDir, 'filing_timeline.md',
            $this->generateFilingTimeline($intake, $playbookResults)
        );

        // ── 8. Write manifest ─────────────────────────────────────────────
        $manifestPath = $this->writeManifest($pkgDir, $intakeId, $files, $errors);

        return [
            'package_dir'   => $pkgDir,
            'manifest_path' => $manifestPath,
            'files'         => $files,
            'errors'        => $errors,
        ];
    }

    // ── Cover memo builder ────────────────────────────────────────────────

    private function buildCoverMemo(
        string $pkgDir,
        array  $intake,
        array  $portfolioContext,
        array  $playbookResults
    ): array {
        $errors = [];
        $md     = CoverMemoGenerator::generate($intake, $portfolioContext, $playbookResults);
        $mdPath = $pkgDir . '/cover_memo.md';
        file_put_contents($mdPath, $md);

        $docxPath = null;
        $pdfPath  = null;

        if (PandocConverter::available()) {
            $docxPath = $pkgDir . '/cover_memo.docx';
            if (!$this->pandoc->mdToDocx($mdPath, $docxPath)) {
                $errors[]  = 'cover_memo.docx: Pandoc conversion failed';
                $docxPath  = null;
            }
            $pdfPath = $pkgDir . '/cover_memo.pdf';
            if (!$this->pandoc->mdToPdf($mdPath, $pdfPath)) {
                $errors[] = 'cover_memo.pdf: Pandoc/PDF engine conversion failed';
                $pdfPath  = null;
            }
        }

        return [
            'file' => [
                'name'      => 'cover_memo',
                'md_path'   => $mdPath,
                'docx_path' => $docxPath,
                'pdf_path'  => $pdfPath,
                'hash'      => hash('sha256', $md),
                'type'      => 'cover_memo',
            ],
            'errors' => $errors,
        ];
    }

    // ── Formation doc builders ─────────────────────────────────────────────

    private function buildFormationDocs(string $pkgDir, array $intake, int $intakeId): array
    {
        $errors       = [];
        $files        = [];
        $slugsRendered = [];

        $jur        = (string)($intake['decided_jurisdiction'] ?? ($intake['suggested_jurisdiction'] ?? 'TX'));
        $entityType = (string)($intake['decided_entity_type']  ?? ($intake['suggested_entity_type']  ?? 'llc'));

        // Collect template slugs for this structure
        $slugs = self::UNIVERSAL_SLUGS;
        $jurMap = self::FORMATION_SLUG_MAP[$jur] ?? self::FORMATION_SLUG_MAP['TX'];
        $typeMap = $jurMap[$entityType] ?? ($jurMap['llc'] ?? []);
        $slugs  = array_unique(array_merge($typeMap, $slugs));

        // Build variable map from intake row
        $vars = $this->buildVariablesFromIntake($intake);

        foreach ($slugs as $slug) {
            $tpl = $this->fetchTemplateBySlug($slug);
            if ($tpl === null) {
                $errors[] = "Template slug '{$slug}' not found in doc_templates — skipped.";
                continue;
            }

            $result = $this->renderer->render((int)$tpl['id'], $vars, $intakeId);

            if (!empty($result['missing_vars'])) {
                $errors[] = "Template '{$slug}' missing vars: "
                    . implode(', ', $result['missing_vars']) . ' — skipped.';
                continue;
            }

            $slugsRendered[] = $slug;
            $fileName = preg_replace('/[^a-z0-9_-]/', '_', $slug);
            $mdPath   = $pkgDir . '/' . $fileName . '.md';
            file_put_contents($mdPath, $result['rendered_md']);

            $docxPath = null;
            $pdfPath  = null;

            if (PandocConverter::available()) {
                $docxPath = $pkgDir . '/' . $fileName . '.docx';
                if (!$this->pandoc->mdToDocx($mdPath, $docxPath)) {
                    $errors[] = "{$fileName}.docx: Pandoc conversion failed";
                    $docxPath = null;
                }
                $pdfPath = $pkgDir . '/' . $fileName . '.pdf';
                if (!$this->pandoc->mdToPdf($mdPath, $pdfPath)) {
                    $errors[] = "{$fileName}.pdf: Pandoc/PDF conversion failed";
                    $pdfPath = null;
                }
            }

            // Update doc_renders with file paths if we got them
            if ($result['render_id'] !== null) {
                $relPdf  = $pdfPath  ? $this->toRelativePath($pdfPath)  : null;
                $relDocx = $docxPath ? $this->toRelativePath($docxPath) : null;
                $this->renderer->storeFilePaths($result['render_id'], $relPdf, $relDocx);
            }

            $files[] = [
                'name'      => $fileName,
                'slug'      => $slug,
                'md_path'   => $mdPath,
                'docx_path' => $docxPath,
                'pdf_path'  => $pdfPath,
                'hash'      => $result['content_hash'],
                'render_id' => $result['render_id'],
                'type'      => 'formation_doc',
            ];
        }

        return [
            'files'         => $files,
            'errors'        => $errors,
            'slugs_rendered' => $slugsRendered,
        ];
    }

    // ── Static doc builders ───────────────────────────────────────────────

    /** Write a markdown string to a file; optionally convert via Pandoc. */
    private function buildStaticDoc(string $pkgDir, string $filename, string $md): array
    {
        $mdPath = $pkgDir . '/' . $filename;
        file_put_contents($mdPath, $md);
        $hash     = hash('sha256', $md);
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        $docxPath = null;
        $pdfPath  = null;

        if (PandocConverter::available()) {
            $docxPath = $pkgDir . '/' . $baseName . '.docx';
            if (!$this->pandoc->mdToDocx($mdPath, $docxPath)) {
                $docxPath = null;
            }
            $pdfPath = $pkgDir . '/' . $baseName . '.pdf';
            if (!$this->pandoc->mdToPdf($mdPath, $pdfPath)) {
                $pdfPath = null;
            }
        }

        return [
            'name'      => $baseName,
            'md_path'   => $mdPath,
            'docx_path' => $docxPath,
            'pdf_path'  => $pdfPath,
            'hash'      => $hash,
            'type'      => 'static',
        ];
    }

    // ── Static content generators ─────────────────────────────────────────

    private function generateAttorneyChecklist(array $intake, array $slugsRendered): string
    {
        $jur        = (string)($intake['decided_jurisdiction'] ?? 'TX');
        $entityType = (string)($intake['decided_entity_type']  ?? 'llc');
        $date       = date('F j, Y');

        $docChecks = '';
        foreach ($slugsRendered as $slug) {
            $docChecks .= "- [ ] **{$slug}**: Review for completeness, state-specific requirements, "
                . "and client-specific accuracy.\n";
        }
        if (empty($docChecks)) {
            $docChecks = "- [ ] No templates were rendered. Verify template library is seeded.\n";
        }

        return <<<EOT
        # Attorney Review Checklist
        **Date:** {$date}
        **Jurisdiction:** {$jur} | **Entity Type:** {$entityType}

        ---

        ## Per-Document Verification

        {$docChecks}

        ---

        ## Cross-Document Consistency Checks

        - [ ] Entity name is identical across ALL documents (check for typos, abbreviations)
        - [ ] Formation date is consistent with BOI filing deadline calculation
        - [ ] Registered agent name and address are current and correct in every document
        - [ ] EIN (if already obtained) is correctly reflected in all post-EIN documents
        - [ ] Owner names, percentages, and addresses match across OA, BOI, and any stock docs
        - [ ] Jurisdiction-specific language is appropriate (e.g., TX "member" vs DE "stockholder")

        ---

        ## State-Specific Checks ({$jur})

        EOT
        . $this->jurSpecificChecks($jur)
        . "\n\n---\n\n*Checklist generated by DST Empire — VoltOps SaaS. {$date}.*\n";
    }

    private function jurSpecificChecks(string $jur): string
    {
        return match ($jur) {
            'TX' => "- [ ] TX BOC §3.005 public information report due annually by May 15\n"
                . "- [ ] TX franchise tax: confirm entity qualifies for no-tax-due threshold (<\$2.47M revenue)\n"
                . "- [ ] Assumed Name Certificate filed in county of principal office if DBA used\n",
            'DE' => "- [ ] Registered agent in DE (Corporation Service Company, Incorp, or equivalent)\n"
                . "- [ ] Annual report + franchise tax due March 1 (min \$50, use assumed par value method)\n"
                . "- [ ] Certificate of Good Standing obtainable from DE SOS at any time\n",
            'WY' => "- [ ] WY registered agent required in state\n"
                . "- [ ] Annual report due first day of anniversary month (\$60 min filing fee)\n"
                . "- [ ] Confirm no WY income tax on LLC profits (pass-through)\n",
            'NV' => "- [ ] NV registered agent in state required\n"
                . "- [ ] Annual report + state business license (\$200) due by last day of anniversary month\n"
                . "- [ ] NV no corporate income tax — confirm entity taxation treatment\n",
            default => "- [ ] Verify annual report filing requirements in {$jur}\n"
                . "- [ ] Confirm registered agent requirements in {$jur}\n",
        };
    }

    private function generateEngagementLetter(array $intake): string
    {
        $date       = date('F j, Y');
        $jur        = (string)($intake['decided_jurisdiction'] ?? 'TX');
        $entityType = strtoupper((string)($intake['decided_entity_type'] ?? 'llc'));
        $brandName  = (string)($intake['brand_name'] ?? '[CLIENT BRAND]');

        return <<<EOT
        # Engagement Letter — Business Formation Review

        **Date:** {$date}
        **Client:** [CLIENT FULL LEGAL NAME]
        **Matter:** Review and supervision of {$entityType} formation in {$jur} for {$brandName}
        **Prepared by:** [ATTORNEY NAME], [STATE BAR #]

        ---

        ## 1. Scope of Engagement

        Attorney agrees to provide the following services:

        1. Review of all formation documents prepared by client's planning software (DST Empire)
        2. Identify deficiencies, state-specific non-compliance, and legal risks in draft documents
        3. Prepare a written summary of recommended revisions (within 14 calendar days of engagement)
        4. Supervise execution and filing of approved documents
        5. Advise on BOI filing requirements under the Corporate Transparency Act (CTA)
        6. Review and advise on IP assignment agreement

        **NOT included** in this engagement unless separately agreed in writing:
        - Tax advice, CPA services, or IRS representation
        - Trademark registration or IP prosecution
        - Ongoing compliance monitoring after formation
        - Representation in any dispute or litigation

        ---

        ## 2. Fee Arrangement

        **Option A — Flat Fee:** \$[AMOUNT] for the full scope above.

        **Option B — Hourly:** \$[RATE]/hr, capped at \$[CAP] for this engagement.
        Any work exceeding the cap requires written pre-authorization.

        Payment due within 14 days of invoice. Work commences upon receipt of retainer.

        ---

        ## 3. Timeline

        | Milestone | Target |
        |---|---|
        | Attorney document review complete | 14 days from engagement |
        | Revised documents delivered to client | 21 days from engagement |
        | Formation documents filed | 30 days from engagement |
        | BOI filed at FinCEN | Within 30 days of entity formation |

        ---

        ## 4. Termination

        Either party may terminate this engagement upon 7 days written notice.
        Client owes fees for work completed through termination date.
        All work product (drafts, memos, revisions) becomes client property upon
        full payment of fees earned.

        ---

        ## 5. IP Rights to Draft Documents

        All draft documents in the attorney-ready package were prepared using
        public-domain and open-license templates. Attorney's revisions become
        client property upon payment. Attorney retains no license or right to
        reuse client-specific content.

        ---

        ## 6. Conflicts + Disclosures

        - [ ] Attorney confirms no conflict of interest with client or any counterparty
        - [ ] Attorney confirms no referral fees from filing services, registered agents,
              or insurance providers recommended in connection with this matter
        - [ ] Attorney discloses any dual representation: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

        ---

        *Accepted by Client:* \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_

        *Accepted by Attorney:* \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_

        ---

        *Generated by DST Empire — VoltOps SaaS. {$date}. Not legal advice.*
        EOT;
    }

    private function generateRedFlagsList(): string
    {
        $date = date('F j, Y');

        return <<<EOT
        # Attorney Red Flags List — "Don't Get Screwed"

        *Review this list before signing any engagement. If your attorney exhibits
        multiple of these patterns, seek a second opinion before proceeding.*

        **Date:** {$date}

        ---

        ## Fee Red Flags

        - **Hourly rate >$700/hr without board-cert specialization** — general business
          formation work should not command top-tier IP/securities rates. If the rate is
          that high, demand a flat-fee alternative.
        - **Flat fee with open-ended scope creep clauses** — "additional services billed
          at hourly rate" with no defined cap means unlimited exposure. Always require a
          written cap on additional fees.
        - **Retainer required before any discussion of scope** — legitimate business
          attorneys should be willing to quote a flat fee before taking a retainer.

        ---

        ## Conflict Red Flags

        - **Kickback from filing services** (LegalZoom, ZenBusiness, Incfile, Northwest) —
          if your attorney refers you to a filing service and earns a referral fee, that
          is a conflict of interest. Ask directly.
        - **Referral fees from registered agent providers** — same conflict applies.
        - **Captive insurance referral fees from a specific carrier** — micro-captive
          arrangements with a predetermined carrier are an audit red flag. The carrier
          selection should be arm's-length.
        - **"We have a financial planner we always work with"** — integrated teams can
          be legitimate, but verify no undisclosed fee-sharing arrangements.

        ---

        ## Competence Red Flags

        - **No state bar membership in the formation state** — attorney must be licensed
          in the jurisdiction where they are providing formation advice. Check state bar
          website before engaging.
        - **"We always do it this way" without discussing alternatives** — a good
          formation attorney adapts to your structure, not the other way around.
        - **Unfamiliarity with the Corporate Transparency Act (CTA/BOI)** — this has been
          law since Jan 1, 2024. Any business attorney should know BOI filing requirements.
        - **Refusal to review pre-drafted documents** — if an attorney refuses to review
          documents prepared by your planning software and insists on starting from scratch,
          they may be billing to reinvent the wheel. Clarify scope and get a flat fee.
        - **No response within 48h to initial inquiry** — for time-sensitive filings
          (83(b) election = 30 days, BOI = 30 days, S-Corp = 75 days), you need an
          attorney who responds promptly.

        ---

        ## Structure Red Flags

        - **Recommending C-Corp for every client** — not everyone needs a C-Corp. If
          QSBS is not in your plan, a C-Corp adds double taxation risk.
        - **Dismissing Wyoming or Nevada LLC** — some attorneys default to Delaware for
          everything. WY and NV have strong asset protection statutes with lower annual costs.
        - **No discussion of operating agreement customization** — boilerplate OAs leave
          member rights undefined. Push for customized profit/loss allocations, manager
          authority limits, and dissolution mechanics.

        ---

        ## The Hard Walk-Away Conditions

        Walk away immediately if your attorney:

        1. Asks you to sign a document without reading it yourself first
        2. Claims guaranteed outcomes on tax savings
        3. Suggests "not to worry about" the BOI requirement
        4. Cannot explain in plain English what each document does
        5. Pressures you to decide on the spot without time to review

        ---

        *Generated by DST Empire — VoltOps SaaS. {$date}. Not legal advice.*
        EOT;
    }

    private function generateClientChecklist(array $intake): string
    {
        $date       = date('F j, Y');
        $jur        = (string)($intake['decided_jurisdiction'] ?? 'TX');
        $entityType = strtoupper((string)($intake['decided_entity_type'] ?? 'llc'));
        $brandName  = (string)($intake['brand_name'] ?? '[Brand]');

        return <<<EOT
        # Client Self-Do Checklist — {$brandName}

        *Tasks YOU complete without attorney. Free or low-cost. Estimated time shown.*

        **Date:** {$date}
        **Entity:** {$entityType} in {$jur}

        ---

        ## Immediate (before or same day as filing)

        - [ ] **Get EIN at IRS.gov/EIN** — free, 5 min, instant online.
          Use your Social Security Number as responsible party. Entity must be
          formed first (have Articles in hand). URL: https://www.irs.gov/businesses/small-businesses-self-employed/apply-for-an-employer-identification-number-ein-online
        - [ ] **Open business bank account** — Mercury (free, online, ACH same day)
          or Chase Business Complete. Bring: Articles + EIN confirmation letter.
          URL (Mercury): https://mercury.com — typically 1–3 business days to approve.
        - [ ] **File BOI at FinCEN within 30 days of formation** — free.
          URL: https://boiefiling.fincen.gov/
          Need: entity legal name, EIN, formation date, beneficial owners' legal names,
          addresses, DOB, and ID document copy.

        ---

        ## First 30 Days

        - [ ] **Update domain registrar contact info** — change WHOIS registrant to entity
          name + entity address (not personal address).
        - [ ] **Update Google Business Profile** — change business name to entity DBA
          (if applicable) and address.
        - [ ] **Set up accounting** — open a QBO file (or equivalent).
          QB Simple Start: ~\$30/mo. Free option: Wave Accounting.
        - [ ] **Get business insurance binder** — call a local commercial insurance broker
          (NOT online only). Ask for GL + E&O quote. Do not skip this step.
        - [ ] **Set up payroll if owner is W2 employee** — Gusto (\$40/mo base) or
          QuickBooks Payroll. Required if S-Corp election active.

        EOT
        . ($jur !== 'TX' ? "- [ ] **Appoint registered agent in {$jur}** — required before filing. "
            . "Northwest Registered Agent: ~\$125/yr. InCorp: ~\$99/yr.\n\n" : '')
        . <<<EOT

        ---

        ## First 75 Days (if S-Corp election active)

        - [ ] **File Form 2553** — attorney files, but you must sign. Due within 75 days
          of formation OR by March 15 for next-year election. Missing this deadline
          = no S-Corp treatment until next year. Do NOT miss.
        - [ ] **All shareholders must consent** — Form 2553 requires signatures from
          all shareholders. Get them all on the same day if possible.

        ---

        ## Ongoing (annual)

        - [ ] **Annual report to state** — due dates vary by state.
          {$jur}: see state SOS website for exact due date + fee.
        - [ ] **Franchise tax filing** (TX, DE) — due dates per state comptroller.
        - [ ] **BOI update within 30 days** if any beneficial owner info changes
          (address, new owner, removed owner).
        - [ ] **Review operating agreement annually** — update if ownership, managers,
          or business purpose changes.
        - [ ] **Minute book update** — document major decisions in writing
          (even if not legally required, it demonstrates separateness).

        ---

        *Generated by DST Empire — VoltOps SaaS. {$date}. Not legal advice.*
        EOT;
    }

    private function generateFilingTimeline(array $intake, array $playbookResults): string
    {
        $date       = date('F j, Y');
        $jur        = (string)($intake['decided_jurisdiction'] ?? 'TX');
        $entityType = strtoupper((string)($intake['decided_entity_type'] ?? 'llc'));

        $hasSCorp    = $this->playbookActive($playbookResults, 's_corp_election');
        $hasQSBS     = $this->playbookActive($playbookResults, 'qsbs_1202');
        $has83b      = $this->playbookActive($playbookResults, 'qsbs_1202') || $hasSCorp;

        $items = [];

        // Day 0 = formation date (t0)
        $items[] = ['day' => 'Day 0',      'task' => "File {$entityType} Articles in {$jur}",
                    'deadline' => 'Day 0 (formation date)',     'consequence' => 'Entity does not exist without this'];
        $items[] = ['day' => 'Day 1–3',    'task' => 'Get EIN at IRS.gov',
                    'deadline' => 'Before opening bank account', 'consequence' => 'Cannot open business bank account without EIN'];
        $items[] = ['day' => 'Day 1–3',    'task' => 'Appoint registered agent (if out-of-state)',
                    'deadline' => 'Same day as or before filing', 'consequence' => 'Required for filing'];
        $items[] = ['day' => 'Day 3–7',    'task' => 'Open business bank account',
                    'deadline' => 'Before any revenue received', 'consequence' => 'Commingled funds = piercing risk'];
        $items[] = ['day' => 'Day ≤30',    'task' => 'File BOI at FinCEN (fincen.gov)',
                    'deadline' => '30 days from formation', 'consequence' => '$591/day penalty if late (willful)'];

        if ($hasSCorp) {
            $items[] = ['day' => 'Day ≤75', 'task' => 'File Form 2553 (S-Corp election)',
                        'deadline' => '75 days from formation OR Mar 15',
                        'consequence' => 'No S-Corp treatment until following tax year if missed'];
        }

        if ($has83b) {
            $items[] = ['day' => 'Day ≤30', 'task' => 'File §83(b) election (IRS Form 15620)',
                        'deadline' => '30 days from stock issuance — HARD DEADLINE',
                        'consequence' => 'No late filing allowed by any circumstance. Miss = taxed at FMV on vesting.'];
        }

        if ($hasQSBS) {
            $items[] = ['day' => 'Day 0–30', 'task' => 'Issue stock and document §1202 active business attestation',
                        'deadline' => 'At time of stock issuance',
                        'consequence' => 'QSBS eligibility starts from issuance date — 5-year holding period clock starts'];
        }

        // Annual items
        $annualReport = match ($jur) {
            'TX' => 'Annual Report (TX PIR) — due May 15 each year',
            'DE' => 'Annual Report + Franchise Tax — due March 1 each year',
            'WY' => 'Annual Report — due first day of anniversary month each year',
            'NV' => 'Annual Report + Business License — due last day of anniversary month',
            default => "Annual Report ({$jur}) — see state SOS for due date",
        };
        $items[] = ['day' => 'Year 1+', 'task' => $annualReport,
                    'deadline' => 'Per state schedule',    'consequence' => 'Administrative dissolution if missed'];
        $items[] = ['day' => 'Year 1+', 'task' => 'BOI update (if ownership changes)',
                    'deadline' => '30 days from change',   'consequence' => '$591/day civil fine if willful'];

        // Build table
        $tableRows = '';
        foreach ($items as $item) {
            $tableRows .= "| {$item['day']} | {$item['task']} | {$item['deadline']} "
                . "| {$item['consequence']} |\n";
        }

        return <<<EOT
        # Filing Timeline — {$entityType} in {$jur}

        **Date prepared:** {$date}

        *Critical-path order — complete each step before the next.*

        | Timing | Task | Deadline | Consequence if Missed |
        |---|---|---|---|
        {$tableRows}

        ---

        ## Critical-Path Dependencies

        ```
        Articles filed (Day 0)
          └─ EIN obtained (Day 1–3)
               └─ Bank account opened (Day 3–7)
                    └─ BOI filed (Day ≤30)
        EOT
        . ($hasSCorp ? "\n                    └─ Form 2553 filed (Day ≤75)" : '')
        . ($has83b   ? "\n                    └─ §83(b) election filed (Day ≤30 from stock issuance)" : '')
        . <<<EOT

        ```

        ---

        *Generated by DST Empire — VoltOps SaaS. {$date}. Not legal advice.*
        EOT;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function fetchIntake(int $intakeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM empire_brand_intake WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$intakeId, $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchPortfolioContext(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM empire_portfolio_context WHERE tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    /**
     * Fetch playbook results from the playbook_results JSON column on the intake,
     * or fall back to an empty array if not available.
     * (PortfolioSynthesizer stores results on the intake row or a separate table
     * depending on which parallel agent built first — defensive fallback here.)
     */
    private function fetchPlaybookResults(int $intakeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT playbook_results_json FROM empire_brand_intake
             WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$intakeId, $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['playbook_results_json'])) {
            return [];
        }

        $results = json_decode((string)$row['playbook_results_json'], true);
        return is_array($results) ? $results : [];
    }

    private function fetchTemplateBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM doc_templates WHERE slug = ? LIMIT 1"
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Build variable map from intake row for template substitution.
     * Maps common intake columns to the variable names used in templates.
     */
    private function buildVariablesFromIntake(array $intake): array
    {
        $now   = new \DateTime();
        $vars  = [
            // Entity identity
            'company_name'            => $intake['brand_name']                        ?? '',
            'brand_name'              => $intake['brand_name']                        ?? '',
            'brand_slug'              => $intake['brand_slug']                        ?? '',
            'entity_type'             => strtoupper($intake['decided_entity_type']    ?? ($intake['suggested_entity_type'] ?? 'LLC')),
            'jurisdiction'            => $intake['decided_jurisdiction']              ?? ($intake['suggested_jurisdiction'] ?? 'TX'),
            'state_of_operations'     => $intake['state_of_operations']              ?? 'TX',
            'formation_date'          => $intake['decided_at']                       ?? $now->format('Y-m-d'),
            // Ownership
            'member_name'             => $intake['owner_legal_name']                 ?? '[MEMBER NAME]',
            'member_address'          => $intake['owner_address']                    ?? '[MEMBER ADDRESS]',
            'owner_percent'           => '100%',
            // Management
            'manager_name'            => $intake['owner_legal_name']                 ?? '[MANAGER NAME]',
            'registered_agent_name'   => '[REGISTERED AGENT NAME]',
            'registered_agent_address' => '[REGISTERED AGENT ADDRESS]',
            // Tax
            'ein'                     => $intake['ein']                              ?? '[EIN PENDING]',
            'tax_election'            => $intake['decided_entity_type'] === 'scorp'  ? 'S-Corporation' : 'Disregarded Entity',
            'fiscal_year_end'         => 'December 31',
            // IP
            'ip_description'          => $intake['ip_description']                   ?? '[DESCRIBE ALL INTELLECTUAL PROPERTY]',
            // Dates
            'effective_date'          => $now->format('F j, Y'),
            'preparation_date'        => $now->format('F j, Y'),
            // Trust layer
            'trust_wrapper'           => $intake['decided_trust_wrapper']            ?? 'none',
            'has_partner'             => !empty($intake['co_owner_name']),
            'partner_name'            => $intake['co_owner_name']                    ?? '',
            // Beneficial owners array (for {{#each}} loops)
            'beneficial_owners'       => $this->buildBeneficialOwnersList($intake),
        ];
        return $vars;
    }

    private function buildBeneficialOwnersList(array $intake): array
    {
        $owners = [[
            'name'             => $intake['owner_legal_name']  ?? '[OWNER NAME]',
            'title'            => 'Member / Manager',
            'address'          => $intake['owner_address']     ?? '[ADDRESS]',
            'ownership_percent' => '100%',
            'dob'              => $intake['owner_dob']         ?? '[DOB]',
        ]];
        if (!empty($intake['co_owner_name'])) {
            $owners[] = [
                'name'             => $intake['co_owner_name'],
                'title'            => 'Member',
                'address'          => $intake['co_owner_address'] ?? '[ADDRESS]',
                'ownership_percent' => $intake['co_owner_percent'] ?? '[%]',
                'dob'              => $intake['co_owner_dob']     ?? '[DOB]',
            ];
        }
        return $owners;
    }

    private function makePackageDir(int $intakeId): string
    {
        $ts  = date('YmdHis');
        $dir = self::STORAGE_ROOT . '/' . $this->tenantId . '/' . $intakeId . '/' . $ts;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException("Cannot create package directory: {$dir}");
        }
        return $dir;
    }

    private function writeManifest(
        string $pkgDir,
        int    $intakeId,
        array  $files,
        array  $errors
    ): string {
        $manifest = [
            'generated_at'  => date('c'),
            'intake_id'     => $intakeId,
            'tenant_id'     => $this->tenantId,
            'pandoc'        => PandocConverter::available() ? 'available' : 'not_found',
            'file_count'    => count($files),
            'error_count'   => count($errors),
            'errors'        => $errors,
            'files'         => array_map(function (array $f) {
                return [
                    'name'      => $f['name'],
                    'type'      => $f['type'] ?? 'unknown',
                    'slug'      => $f['slug'] ?? null,
                    'hash'      => $f['hash'] ?? null,
                    'render_id' => $f['render_id'] ?? null,
                    'md'        => $f['md_path']   ? basename($f['md_path'])   : null,
                    'docx'      => $f['docx_path'] ? basename($f['docx_path']) : null,
                    'pdf'       => $f['pdf_path']  ? basename($f['pdf_path'])  : null,
                ];
            }, $files),
        ];

        $path = $pkgDir . '/manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $path;
    }

    /** Convert an absolute storage path to a project-relative path for DB storage. */
    private function toRelativePath(string $abs): string
    {
        $root = realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../..';
        return ltrim(str_replace($root, '', $abs), '/');
    }

    private function playbookActive(array $playbookResults, string $key): bool
    {
        foreach ($playbookResults as $pb) {
            if (($pb['playbook_key'] ?? '') === $key && !empty($pb['firing'])) {
                return true;
            }
        }
        return false;
    }
}
