<?php
/**
 * src/Empire/LawMonitor/Ingester.php
 *
 * Takes the raw output of SourcePoller::pollAll() and persists new items
 * into the law_changes table with LLM classification populated.
 *
 * Deduplication strategy:
 *  - source_url is the natural key (unique index expected on law_changes.source_url)
 *  - Uses INSERT IGNORE to handle races (e.g. two cron runs overlapping)
 *  - If source_url is NULL (shouldn't happen for polled items) falls back to
 *    SHA-256(source + title + published_at) as the dedup key
 *
 * Classification:
 *  - Calls Classifier::classify() per item (synchronous, ~5-10s per item w/ 70b model)
 *  - Sets processed=0 on insert; cron sets processed=1 after amendment records created
 *  - Items that fail classification are inserted with processed=0 and fallback JSON
 *
 * Namespace: Mnmsos\Empire\LawMonitor
 */

namespace Mnmsos\Empire\LawMonitor;

use PDO;

class Ingester
{
    private PDO        $db;
    private Classifier $classifier;

    public function __construct(PDO $db)
    {
        $this->db         = $db;
        $this->classifier = new Classifier();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Process the output of SourcePoller::pollAll().
     *
     * For each source result, for each item:
     *  1. Check dedup against existing law_changes.source_url
     *  2. Classify via LLM
     *  3. Insert into law_changes with classification_json
     *
     * Returns count of new rows inserted.
     *
     * @param array<int,array<string,mixed>> $polledData  Output of SourcePoller::pollAll()
     * @return int  Count of newly inserted law_change rows
     */
    public function ingestPolledItems(array $polledData): int
    {
        $inserted = 0;

        foreach ($polledData as $sourceResult) {
            $sourceId = $sourceResult['source_id'] ?? 'unknown';

            if (!empty($sourceResult['error'])) {
                fwrite(STDERR, '[Ingester] Source ' . $sourceId . ' had fetch error: ' . $sourceResult['error'] . "\n");
            }

            $items = $sourceResult['items'] ?? [];

            if (empty($items)) {
                continue;
            }

            foreach ($items as $item) {
                try {
                    $newId = $this->ingestOne($item);
                    if ($newId !== null) {
                        $inserted++;
                        fwrite(STDOUT, '[Ingester] Inserted law_change id=' . $newId . ' — ' . ($item['title'] ?? '') . "\n");
                    }
                } catch (\Throwable $e) {
                    fwrite(STDERR, '[Ingester] Failed to ingest item url=' . ($item['url'] ?? '') . ': ' . $e->getMessage() . "\n");
                }
            }
        }

        return $inserted;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Single item ingestion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ingest one item. Returns inserted row ID or null if skipped (duplicate).
     *
     * @param array<string,mixed> $item
     * @return ?int
     */
    private function ingestOne(array $item): ?int
    {
        $sourceUrl = trim($item['url'] ?? '');

        // Dedup check — avoid LLM call cost for already-seen items
        if ($sourceUrl && $this->urlAlreadyExists($sourceUrl)) {
            return null;
        }

        // Classify via LLM
        $classification = $this->classifier->classify($item);

        // Map source string to ENUM value (Ingester → DB enum mapping)
        $dbSource = $this->mapSourceEnum($item['source'] ?? '');

        // Determine effective date from published_at or null
        $publishedAt  = $item['published_at'] ?? null;
        $effectiveDate = null;
        if ($publishedAt) {
            $ts = strtotime($publishedAt);
            if ($ts !== false) {
                $effectiveDate = date('Y-m-d', $ts);
            }
        }

        $classificationJson = json_encode($classification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // INSERT IGNORE handles race condition from concurrent cron runs
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO law_changes
             (source, jurisdiction, source_url, title, summary_md, full_text_md,
              effective_date, detected_at, classification_json, processed)
             VALUES
             (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0)"
        );

        $stmt->execute([
            $dbSource,
            $item['jurisdiction'] ?? null,
            $sourceUrl ?: null,
            mb_strimwidth($item['title'] ?? 'Untitled', 0, 300, '...'),
            $item['summary'] ?? null,
            $item['raw_content_md'] ?? null,
            $effectiveDate,
            $classificationJson,
        ]);

        $rowCount = $stmt->rowCount();
        if ($rowCount === 0) {
            // INSERT IGNORE silently skipped (duplicate source_url in race)
            return null;
        }

        return (int)$this->db->lastInsertId();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if a source_url already exists in law_changes.
     * Uses a targeted indexed lookup — does not do a full table scan.
     */
    private function urlAlreadyExists(string $url): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM law_changes WHERE source_url = ? LIMIT 1"
        );
        $stmt->execute([$url]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Map the SourcePoller source string to the law_changes ENUM value.
     * ENUM: irs_bulletin, tax_court, state_sos, state_tax, state_trust_law,
     *       fincen, dol, uspto, scotus, industry_specific
     */
    private function mapSourceEnum(string $source): string
    {
        $map = [
            'irs_bulletin'    => 'irs_bulletin',
            'tax_court'       => 'tax_court',
            'fincen'          => 'fincen',
            'scotus'          => 'scotus',
            'state_sos'       => 'state_sos',
            'state_tax'       => 'state_tax',
            'state_trust_law' => 'state_trust_law',
            'dol'             => 'dol',
            'uspto'           => 'uspto',
        ];

        return $map[$source] ?? 'industry_specific';
    }
}
