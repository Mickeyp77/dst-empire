<?php
/**
 * src/Empire/Plaid/TransactionFetcher.php
 *
 * Pulls transactions from Plaid and stores them in plaid_transactions.
 * UPSERT on plaid_transaction_id UNIQUE — safe to re-run.
 *
 * fetchAndStore()        — single entity, called by AccountLinker backfill
 *                          and by cron for per-entity refresh
 * fetchAllTenantsDaily() — cron entry point, loops all active linked accounts
 *
 * Namespace: Mnmsos\Empire\Plaid
 */

namespace Mnmsos\Empire\Plaid;

use PDO;

class TransactionFetcher
{
    private PDO $db;
    private int $tenantId;

    private const CIPHER = 'AES-256-CBC';

    // ─────────────────────────────────────────────────────────────────────
    // CONSTRUCTOR
    // ─────────────────────────────────────────────────────────────────────

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fetch new transactions for all linked accounts under one entity.
     *
     * Uses last_polled_at per account as the from-date (or $sinceDate override).
     * Returns count of new rows inserted (not updated).
     *
     * @param int            $intakeId
     * @param \DateTime|null $sinceDate  Override start date (e.g. for backfill)
     * @return int           Count of new transactions stored
     */
    public function fetchAndStore(int $intakeId, ?\DateTime $sinceDate = null): int
    {
        $plaid = PlaidClient::fromEnv();
        if ($plaid === null) {
            error_log('[TransactionFetcher] Plaid not configured — skipping fetchAndStore');
            return 0;
        }

        $accounts = $this->fetchLinkedAccounts($intakeId);
        if (empty($accounts)) {
            return 0;
        }

        $totalNew = 0;

        foreach ($accounts as $acct) {
            $accessToken = $this->decryptToken($acct['access_token_encrypted'] ?? '');
            if (!$accessToken) {
                error_log('[TransactionFetcher] Cannot decrypt token for account ' . $acct['id']);
                continue;
            }

            // Determine date range
            $fromDate = $sinceDate;
            if ($fromDate === null) {
                // Use last_polled_at or fallback to 7 days ago
                if (!empty($acct['last_polled_at'])) {
                    $fromDate = new \DateTime($acct['last_polled_at']);
                } else {
                    $fromDate = new \DateTime('-7 days');
                }
            }
            $toDate = new \DateTime(); // today

            $transactions = $plaid->fetchTransactions($accessToken, $fromDate, $toDate);
            if ($transactions === null) {
                // Mark account as error status
                $this->markAccountError((int)$acct['id']);
                continue;
            }

            $newCount = $this->upsertTransactions($intakeId, (string)$acct['plaid_account_id'], $transactions);
            $totalNew += $newCount;

            // Update last_polled_at
            $this->updateLastPolled((int)$acct['id']);
        }

        return $totalNew;
    }

    /**
     * Cron entry point — process every active linked account across all tenants.
     *
     * @return int  Total new transactions ingested across all tenants
     */
    public function fetchAllTenantsDaily(): int
    {
        $plaid = PlaidClient::fromEnv();
        if ($plaid === null) {
            error_log('[TransactionFetcher] Plaid not configured — skipping daily fetch');
            return 0;
        }

        // Get all distinct (tenant_id, intake_id) combos with active accounts
        $stmt = $this->db->query(
            "SELECT DISTINCT tenant_id, intake_id
             FROM plaid_accounts
             WHERE status = 'active'
             ORDER BY tenant_id, intake_id"
        );
        $combos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0;

        foreach ($combos as $combo) {
            $tenantId = (int)$combo['tenant_id'];
            $intakeId = (int)$combo['intake_id'];

            // Use a per-tenant fetcher instance
            $fetcher = new self($this->db, $tenantId);
            $count   = $fetcher->fetchAndStore($intakeId);
            $total  += $count;

            if ($count > 0) {
                error_log("[TransactionFetcher] tenant={$tenantId} intake={$intakeId}: {$count} new transactions");
            }
        }

        return $total;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — DB helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fetch all active plaid_accounts for this entity.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchLinkedAccounts(int $intakeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, plaid_account_id, access_token_encrypted, last_polled_at
             FROM plaid_accounts
             WHERE tenant_id = ? AND intake_id = ? AND status = 'active'
             ORDER BY id"
        );
        $stmt->execute([$this->tenantId, $intakeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * UPSERT transactions into plaid_transactions.
     * Returns count of net-new rows (INSERT rows — not ON DUPLICATE KEY updates).
     *
     * @param int    $intakeId
     * @param string $plaidAccountId
     * @param array  $transactions   Raw Plaid transaction objects
     * @return int
     */
    private function upsertTransactions(int $intakeId, string $plaidAccountId, array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }

        $newCount = 0;

        $stmt = $this->db->prepare(
            "INSERT INTO plaid_transactions
             (tenant_id, intake_id, plaid_account_id, plaid_transaction_id,
              txn_date, amount_usd, merchant_name, category,
              classification, raw_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unknown', ?, NOW())
             ON DUPLICATE KEY UPDATE
              txn_date      = VALUES(txn_date),
              amount_usd    = VALUES(amount_usd),
              merchant_name = VALUES(merchant_name),
              category      = VALUES(category),
              raw_json      = VALUES(raw_json)"
        );

        foreach ($transactions as $txn) {
            $txnId = $txn['transaction_id'] ?? null;
            if (!$txnId) {
                continue;
            }

            // Plaid amounts: positive = debit (money out), negative = credit
            $amount    = (float)($txn['amount'] ?? 0);
            $date      = $txn['date'] ?? date('Y-m-d');
            $merchant  = substr($txn['merchant_name'] ?? $txn['name'] ?? '', 0, 255);
            $category  = $this->flattenCategory($txn['category'] ?? []);
            $rawJson   = json_encode($txn);

            // Track whether this was a net-new insert
            $rowsBefore = $this->countTransaction($txnId);

            $stmt->execute([
                $this->tenantId,
                $intakeId,
                $plaidAccountId,
                $txnId,
                $date,
                $amount,
                $merchant,
                $category,
                $rawJson,
            ]);

            if ($rowsBefore === 0) {
                $newCount++;
            }
        }

        return $newCount;
    }

    /**
     * Check if a transaction already exists (for net-new count).
     */
    private function countTransaction(string $plaidTxnId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM plaid_transactions WHERE plaid_transaction_id = ? LIMIT 1"
        );
        $stmt->execute([$plaidTxnId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Flatten Plaid's nested category array to a single string.
     * e.g. ['Food and Drink', 'Restaurants', 'Fast Food'] → 'Food and Drink > Restaurants > Fast Food'
     */
    private function flattenCategory(array $categories): string
    {
        $clean = array_map('trim', array_filter($categories));
        return substr(implode(' > ', $clean), 0, 255);
    }

    /**
     * Mark account as error (Plaid returned error during fetch).
     */
    private function markAccountError(int $accountId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE plaid_accounts SET status = 'error', updated_at = NOW() WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$accountId, $this->tenantId]);
    }

    /**
     * Update last_polled_at to NOW() for an account.
     */
    private function updateLastPolled(int $accountId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE plaid_accounts SET last_polled_at = NOW(), updated_at = NOW() WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$accountId, $this->tenantId]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Encryption (mirrors AccountLinker)
    // ─────────────────────────────────────────────────────────────────────

    private function decryptToken(string $encrypted): string
    {
        $key = getenv('APP_ENCRYPTION_KEY');
        if (!$key || $encrypted === '') {
            return '';
        }
        $key = substr(str_pad($key, 32, "\0"), 0, 32);

        $parts = explode(':', $encrypted, 2);
        if (count($parts) !== 2) {
            return '';
        }

        [$ivHex, $ctBase64] = $parts;
        $iv    = hex2bin($ivHex);
        $ct    = base64_decode($ctBase64);
        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? '' : $plain;
    }
}
