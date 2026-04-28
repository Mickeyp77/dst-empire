<?php
/**
 * src/Empire/Plaid/AccountLinker.php
 *
 * Handles the full account link flow:
 *   1. Exchange public_token → access_token
 *   2. Encrypt + store in plaid_accounts
 *   3. Fetch initial 30-day transaction backfill
 *   4. Soft-delete (unlink) via /item/remove + status update
 *
 * Encryption: AES-256-CBC via openssl_encrypt.
 * Key: APP_ENCRYPTION_KEY env var (must be 32 bytes).
 * IV: random per row, stored as hex prefix in access_token_encrypted column.
 * Format: hex(iv) + ':' + base64(ciphertext)
 *
 * Namespace: Mnmsos\Empire\Plaid
 */

namespace Mnmsos\Empire\Plaid;

use PDO;

class AccountLinker
{
    private PDO    $db;
    private int    $tenantId;
    private string $encKey;

    private const CIPHER = 'AES-256-CBC';

    // ─────────────────────────────────────────────────────────────────────
    // CONSTRUCTOR
    // ─────────────────────────────────────────────────────────────────────

    public function __construct(PDO $db, int $tenantId)
    {
        $this->db       = $db;
        $this->tenantId = $tenantId;

        $key = getenv('APP_ENCRYPTION_KEY');
        if (!$key || strlen($key) < 16) {
            // Non-fatal — encryption degrades to a logged warning; link flow still proceeds
            // but access tokens will NOT be stored. Cron will skip accounts with null tokens.
            error_log('[AccountLinker] APP_ENCRYPTION_KEY missing or too short — access tokens will not be stored');
            $key = '';
        }
        // Normalize to exactly 32 bytes (pad or truncate)
        $this->encKey = substr(str_pad($key, 32, "\0"), 0, 32);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Full link flow: exchange public_token, store account, backfill transactions.
     *
     * @param int    $intakeId    The brand entity being linked
     * @param string $publicToken From Plaid Link onSuccess callback
     * @return array{
     *   ok: bool,
     *   account_id: int|null,
     *   accounts_linked: int,
     *   transactions_backfilled: int,
     *   error: string|null
     * }
     */
    public function linkAccount(int $intakeId, string $publicToken): array
    {
        $plaid = PlaidClient::fromEnv();
        if ($plaid === null) {
            return ['ok' => false, 'account_id' => null, 'accounts_linked' => 0, 'transactions_backfilled' => 0, 'error' => 'Plaid not configured'];
        }

        // Step 1: Exchange token
        $exchange = $plaid->exchangePublicToken($publicToken);
        if ($exchange === null) {
            return ['ok' => false, 'account_id' => null, 'accounts_linked' => 0, 'transactions_backfilled' => 0, 'error' => 'Token exchange failed'];
        }

        $accessToken = $exchange['access_token'];
        $itemId      = $exchange['item_id'] ?? '';

        // Step 2: Fetch account details from Plaid
        $plaidAccounts = $plaid->fetchAccounts($accessToken);
        if ($plaidAccounts === null) {
            $plaidAccounts = [];
        }

        $encryptedToken  = $this->encryptToken($accessToken);
        $accountsInserted = 0;
        $lastInsertId     = null;

        foreach ($plaidAccounts as $acct) {
            $plaidAccountId = $acct['account_id'] ?? '';
            if (!$plaidAccountId) {
                continue;
            }

            // UPSERT: update if account already exists (re-link scenario)
            $stmt = $this->db->prepare(
                "INSERT INTO plaid_accounts
                 (tenant_id, intake_id, plaid_item_id, plaid_account_id,
                  account_name, account_type, account_subtype, mask,
                  institution_name, access_token_encrypted, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                  access_token_encrypted = VALUES(access_token_encrypted),
                  account_name           = VALUES(account_name),
                  status                 = 'active',
                  updated_at             = NOW()"
            );
            $stmt->execute([
                $this->tenantId,
                $intakeId,
                $itemId,
                $plaidAccountId,
                substr($acct['name'] ?? 'Account', 0, 160),
                $acct['type']            ?? 'depository',
                $acct['subtype']         ?? null,
                substr($acct['mask'] ?? '', 0, 4),
                substr($acct['institution_name'] ?? '', 0, 120),
                $encryptedToken,
            ]);

            if ($this->db->lastInsertId()) {
                $lastInsertId = (int)$this->db->lastInsertId();
            }
            $accountsInserted++;
        }

        // If Plaid returned no accounts (sandbox quirk), insert a placeholder row
        if ($accountsInserted === 0) {
            $stmt = $this->db->prepare(
                "INSERT INTO plaid_accounts
                 (tenant_id, intake_id, plaid_item_id, plaid_account_id,
                  account_name, account_type, access_token_encrypted, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'Linked Account', 'depository', ?, 'active', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                  access_token_encrypted = VALUES(access_token_encrypted),
                  status = 'active', updated_at = NOW()"
            );
            $stmt->execute([
                $this->tenantId,
                $intakeId,
                $itemId,
                $itemId . '_placeholder',
                $encryptedToken,
            ]);
            $lastInsertId = (int)$this->db->lastInsertId();
            $accountsInserted = 1;
        }

        // Step 3: Backfill 30 days of transactions
        $txCount = 0;
        try {
            $fetcher  = new TransactionFetcher($this->db, $this->tenantId);
            $txCount  = $fetcher->fetchAndStore($intakeId, new \DateTime('-30 days'));
        } catch (\Throwable $e) {
            error_log('[AccountLinker] Backfill failed: ' . $e->getMessage());
        }

        return [
            'ok'                    => true,
            'account_id'            => $lastInsertId,
            'accounts_linked'       => $accountsInserted,
            'transactions_backfilled' => $txCount,
            'error'                 => null,
        ];
    }

    /**
     * List all linked accounts for a given entity.
     *
     * @param int $intakeId
     * @return array<int,array<string,mixed>>
     */
    public function listLinkedAccounts(int $intakeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, plaid_account_id, account_name, account_type, account_subtype,
                    mask, institution_name, last_polled_at, status, created_at
             FROM plaid_accounts
             WHERE tenant_id = ? AND intake_id = ? AND status != 'removed'
             ORDER BY institution_name, account_name"
        );
        $stmt->execute([$this->tenantId, $intakeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unlink an account: calls Plaid /item/remove, then soft-deletes the row.
     *
     * @param int $accountId  PK of plaid_accounts row
     * @return bool
     */
    public function unlinkAccount(int $accountId): bool
    {
        // Fetch the row to get access_token
        $stmt = $this->db->prepare(
            "SELECT access_token_encrypted, plaid_item_id
             FROM plaid_accounts
             WHERE id = ? AND tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$accountId, $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            error_log("[AccountLinker] unlinkAccount: account {$accountId} not found for tenant {$this->tenantId}");
            return false;
        }

        // Attempt Plaid-side removal (non-fatal if it fails)
        $accessToken = $this->decryptToken($row['access_token_encrypted'] ?? '');
        if ($accessToken) {
            $plaid = PlaidClient::fromEnv();
            if ($plaid !== null) {
                $plaid->removeItem($accessToken);
            }
        }

        // Soft-delete: set status = 'removed', clear encrypted token
        $stmt = $this->db->prepare(
            "UPDATE plaid_accounts
             SET status = 'removed', access_token_encrypted = NULL, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$accountId, $this->tenantId]);
        return (bool)$stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — Encryption helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Encrypt an access token using AES-256-CBC.
     * Returns '' if encryption key is not set (logged at construction).
     * Format: hex(iv) . ':' . base64(ciphertext)
     */
    private function encryptToken(string $plaintext): string
    {
        if (!$this->encKey || $plaintext === '') {
            return '';
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv    = openssl_random_pseudo_bytes($ivLen);
        $ct    = openssl_encrypt($plaintext, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $iv);

        if ($ct === false) {
            error_log('[AccountLinker] openssl_encrypt failed');
            return '';
        }

        return bin2hex($iv) . ':' . base64_encode($ct);
    }

    /**
     * Decrypt an access token. Returns '' on failure.
     */
    private function decryptToken(string $encrypted): string
    {
        if (!$this->encKey || $encrypted === '') {
            return '';
        }

        $parts = explode(':', $encrypted, 2);
        if (count($parts) !== 2) {
            error_log('[AccountLinker] decryptToken: bad format');
            return '';
        }

        [$ivHex, $ctBase64] = $parts;
        $iv = hex2bin($ivHex);
        $ct = base64_decode($ctBase64);

        $plain = openssl_decrypt($ct, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            error_log('[AccountLinker] openssl_decrypt failed');
            return '';
        }

        return $plain;
    }
}
