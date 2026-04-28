<?php
/**
 * src/Empire/Plaid/PlaidClient.php
 *
 * Thin cURL wrapper around the Plaid API.
 * Supports sandbox / development / production environments.
 *
 * Credentials read from env vars only (never hardcoded):
 *   PLAID_CLIENT_ID, PLAID_SECRET, PLAID_ENV
 *
 * All methods return null + log to error_log on Plaid API failure.
 * No exceptions are thrown — callers check for null.
 *
 * Namespace: Mnmsos\Empire\Plaid
 */

namespace Mnmsos\Empire\Plaid;

class PlaidClient
{
    private const BASE_URLS = [
        'sandbox'     => 'https://sandbox.plaid.com',
        'development' => 'https://development.plaid.com',
        'production'  => 'https://production.plaid.com',
    ];

    private const TIMEOUT_SECONDS = 30;

    private string $clientId;
    private string $secret;
    private string $baseUrl;
    private string $env;

    // ─────────────────────────────────────────────────────────────────────
    // CONSTRUCTOR
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param string $clientId  PLAID_CLIENT_ID (from env)
     * @param string $secret    PLAID_SECRET (from env)
     * @param string $env       sandbox|development|production
     */
    public function __construct(string $clientId, string $secret, string $env = 'sandbox')
    {
        $this->clientId = $clientId;
        $this->secret   = $secret;
        $this->env      = $env;
        $this->baseUrl  = self::BASE_URLS[$env] ?? self::BASE_URLS['sandbox'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // FACTORY — build from .env
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Instantiate from environment variables.
     * Returns null if required vars are missing (creds not yet set up).
     */
    public static function fromEnv(): ?self
    {
        $clientId = getenv('PLAID_CLIENT_ID');
        $secret   = getenv('PLAID_SECRET');
        $env      = getenv('PLAID_ENV') ?: 'sandbox';

        if (!$clientId || !$secret) {
            error_log('[PlaidClient] PLAID_CLIENT_ID or PLAID_SECRET not set in env');
            return null;
        }

        return new self($clientId, $secret, $env);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create a link_token for client-side Plaid Link initialization.
     *
     * @param int $tenantId  Internal tenant ID (used as user identifier)
     * @param int $intakeId  Entity (brand intake) being linked
     * @return string|null   The link_token, or null on failure
     */
    public function createLinkToken(int $tenantId, int $intakeId): ?string
    {
        $payload = [
            'client_id'    => $this->clientId,
            'secret'       => $this->secret,
            'client_name'  => 'VoltOps Empire',
            'country_codes' => ['US'],
            'language'     => 'en',
            'user'         => [
                'client_user_id' => 'tenant_' . $tenantId . '_intake_' . $intakeId,
            ],
            'products'     => ['transactions'],
        ];

        $result = $this->post('/link/token/create', $payload);
        if ($result === null) {
            return null;
        }

        if (!isset($result['link_token'])) {
            error_log('[PlaidClient] createLinkToken: no link_token in response: ' . json_encode($result));
            return null;
        }

        return $result['link_token'];
    }

    /**
     * Exchange a short-lived public_token for a permanent access_token.
     *
     * @param string $publicToken  From Plaid Link onSuccess callback
     * @return array|null          ['access_token' => ..., 'item_id' => ...], or null on failure
     */
    public function exchangePublicToken(string $publicToken): ?array
    {
        $payload = [
            'client_id'    => $this->clientId,
            'secret'       => $this->secret,
            'public_token' => $publicToken,
        ];

        $result = $this->post('/item/public_token/exchange', $payload);
        if ($result === null) {
            return null;
        }

        if (!isset($result['access_token'])) {
            error_log('[PlaidClient] exchangePublicToken: no access_token in response');
            return null;
        }

        return [
            'access_token' => $result['access_token'],
            'item_id'      => $result['item_id'] ?? null,
            'request_id'   => $result['request_id'] ?? null,
        ];
    }

    /**
     * Fetch transactions for a date range.
     *
     * @param string         $accessToken  Permanent access token
     * @param \DateTime|null $from         Start date (defaults to 30 days ago)
     * @param \DateTime|null $to           End date (defaults to today)
     * @return array|null    Raw transactions array, or null on failure
     */
    public function fetchTransactions(string $accessToken, ?\DateTime $from = null, ?\DateTime $to = null): ?array
    {
        $startDate = ($from ?? new \DateTime('-30 days'))->format('Y-m-d');
        $endDate   = ($to   ?? new \DateTime())->format('Y-m-d');

        $payload = [
            'client_id'    => $this->clientId,
            'secret'       => $this->secret,
            'access_token' => $accessToken,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'options'      => [
                'count'  => 500,
                'offset' => 0,
            ],
        ];

        $result = $this->post('/transactions/get', $payload);
        if ($result === null) {
            return null;
        }

        // Paginate if total_transactions > 500
        $transactions = $result['transactions'] ?? [];
        $total        = (int)($result['total_transactions'] ?? 0);

        while (count($transactions) < $total) {
            $payload['options']['offset'] = count($transactions);
            $page = $this->post('/transactions/get', $payload);
            if ($page === null) {
                break;
            }
            $batch = $page['transactions'] ?? [];
            if (empty($batch)) {
                break;
            }
            $transactions = array_merge($transactions, $batch);
        }

        return $transactions;
    }

    /**
     * Fetch all accounts linked to an item.
     *
     * @param string $accessToken
     * @return array|null  Array of account objects, or null on failure
     */
    public function fetchAccounts(string $accessToken): ?array
    {
        $payload = [
            'client_id'    => $this->clientId,
            'secret'       => $this->secret,
            'access_token' => $accessToken,
        ];

        $result = $this->post('/accounts/get', $payload);
        if ($result === null) {
            return null;
        }

        return $result['accounts'] ?? [];
    }

    /**
     * Remove a Plaid item (revoke access token on Plaid side).
     *
     * @param string $accessToken
     * @return bool  True if successfully removed
     */
    public function removeItem(string $accessToken): bool
    {
        $payload = [
            'client_id'    => $this->clientId,
            'secret'       => $this->secret,
            'access_token' => $accessToken,
        ];

        $result = $this->post('/item/remove', $payload);
        return $result !== null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE — HTTP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST JSON to a Plaid endpoint.
     * Returns decoded array on success, null on any error (4xx/5xx/network).
     *
     * @param string $path     e.g. '/link/token/create'
     * @param array  $payload  Request body (will be JSON-encoded)
     * @return array|null
     */
    private function post(string $path, array $payload): ?array
    {
        $url  = $this->baseUrl . $path;
        $body = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Plaid-Version: 2020-09-14',
            ],
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('[PlaidClient] cURL error on ' . $path . ': ' . $curlError);
            return null;
        }

        $decoded = json_decode($rawResponse, true);

        if ($httpCode >= 400) {
            $errType = $decoded['error_type'] ?? 'UNKNOWN';
            $errCode = $decoded['error_code'] ?? 'UNKNOWN';
            $errMsg  = $decoded['error_message'] ?? $rawResponse;
            error_log("[PlaidClient] HTTP {$httpCode} on {$path}: [{$errType}/{$errCode}] {$errMsg}");
            return null;
        }

        if (!is_array($decoded)) {
            error_log('[PlaidClient] Non-JSON response from ' . $path . ': ' . substr($rawResponse, 0, 200));
            return null;
        }

        return $decoded;
    }
}
