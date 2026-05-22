<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NibssPayService
{
    private string $authUrl;
    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $subscriptionKey;

    private const TOKEN_CACHE_KEY = 'nibss_pay_access_token';

    public function __construct()
    {
        $this->authUrl         = config('services.nibss_pay.auth_url');
        $this->apiUrl          = rtrim(config('services.nibss_pay.api_url'), '/');
        $this->clientId        = config('services.nibss_pay.client_id');
        $this->clientSecret    = config('services.nibss_pay.client_secret');
        $this->subscriptionKey = config('services.nibss_pay.subscription_key');
    }

    /**
     * Returns a valid access token.
     * Fetches from cache if available; otherwise authenticates and caches the new token
     * with TTL = expires_in - 60s so we never use a near-expired token.
     */
    private function getToken(): string
    {
        $token = Cache::get(self::TOKEN_CACHE_KEY);
        if ($token) {
            return $token;
        }
        log::info('No cached NIBSS PAY token found. Authenticating...');
        $response = Http::asForm()->timeout(15)->post($this->authUrl, [
            'client_id'     => $this->clientId,
            'scope'         => "{$this->clientId}/.default",
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ]);

        if (!$response->successful()) {
            Log::error('NIBSS PAY authentication failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('NIBSS PAY authentication failed: ' . $response->status());
        }

        $body  = $response->json();
        $token = $body['access_token'] ?? null;

        Log::info('NIBSS PAY auth response received', [
            'keys'       => array_keys($body ?? []),
            'token_present' => !empty($token),
        ]);

        if (empty($token)) {
            Log::error('NIBSS PAY auth succeeded (HTTP 200) but no access_token in response', [
                'body' => $body,
            ]);
            throw new \RuntimeException('NIBSS PAY auth returned no access_token. Check response keys in logs.');
        }

        $expiresIn = (int) ($body['expires_in'] ?? 3600);
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /**
     * Central HTTP helper. All public API methods go through here.
     * Automatically injects a fresh (or cached) bearer token on every call.
     */
    private function request(string $method, string $endpoint, array $payload = []): array
    {
        try {
            $token    = $this->getToken();
            $fullUrl  = "{$this->apiUrl}/{$endpoint}";

            Log::info('NIBSS PAY outgoing request', [
                'method'        => strtoupper($method),
                'url'           => $fullUrl,
                'token_preview' => substr($token, 0, 20) . '...' . substr($token, -10),
            ]);

            $http = Http::withToken($token)
                ->when($this->subscriptionKey, fn ($h) => $h->withHeaders([
                    'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
                ]))
                ->timeout(30);

            $response = match (strtoupper($method)) {
                'GET'  => $http->get($fullUrl, $payload),
                'POST' => $http->post($fullUrl, $payload),
                'PUT'  => $http->put($fullUrl, $payload),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            $body = $response->json();

            if ($response->successful()) {
                return ['success' => true, 'data' => $body, 'message' => 'Request successful.'];
            }

            Log::warning('NIBSS PAY API error response', [
                'endpoint' => $endpoint,
                'status'   => $response->status(),
                'body'     => $body,
            ]);

            return [
                'success' => false,
                'data'    => null,
                'message' => $body['message'] ?? 'NIBSS PAY request failed.',
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('NIBSS PAY connection error', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);
            return ['success' => false, 'data' => null, 'message' => 'Could not reach NIBSS PAY. Please try again.'];
        } catch (\RuntimeException $e) {
            return ['success' => false, 'data' => null, 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Public API methods — add your NIBSS PAY endpoints here
    // -------------------------------------------------------------------------

    public function initiatePayment(array $payload): array
    {
        return $this->request('POST', 'payments', $payload);
    }

    public function getAccounts(array $payload): array
    {
        return $this->request('GET', 'accounts', $payload);
    }

    public function getBankList(): array
    {
        return $this->request('GET', 'banks');
    }

    public function createSchedule(array $payload): array
    {
        return $this->request('POST', 'schedules/create', $payload);
    }

    public function getSchedule(): array
    {
        return $this->request('GET', 'schedules');
    }

    public function postAccount(array $payload): array
    {
        return $this->request('POST', 'accounts', $payload);
    }
}
