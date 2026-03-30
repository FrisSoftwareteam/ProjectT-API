<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 
 ** Endpoints I created:
 *  GET https://api.paystack.co/bank                              → list all banks
 *  GET https://api.paystack.co/bank/resolve?account_number=&bank_code=  → verify account
 *
 */
class PaystackService
{
    private string $baseUrl;
    private string $secretKey;

    // Bank list is stable — cache it for 24 hours to avoid hammering Paystack
    private const BANK_LIST_CACHE_KEY = 'paystack_bank_list';
    private const BANK_LIST_CACHE_TTL = 86400; // 24 hours in seconds

    public function __construct()
    {
        $this->baseUrl   = rtrim(config('services.paystack.base_url', 'https://api.paystack.co'), '/');
        $this->secretKey = config('services.paystack.secret_key', '');
    }

    /**
     * Resolve (verify) a bank account number.
     *
     * @param  string  $accountNumber  10-digit NUBAN account number
     * @param  string  $bankCode       Paystack bank code (e.g. "058" for GTBank)
     * @return array{
     *   success: bool,
     *   account_name: string|null,
     *   account_number: string|null,
     *   bank_code: string|null,
     *   bank_name: string|null,
     *   message: string,
     *   raw: array|null
     * }
     */
    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        // Defensive: strip whitespace that might come from the request
        $accountNumber = trim($accountNumber);
        $bankCode      = trim($bankCode);

        try {
            $response = Http::withToken($this->secretKey)
                ->timeout(15)
                ->get("{$this->baseUrl}/bank/resolve", [
                    'account_number' => $accountNumber,
                    'bank_code'      => $bankCode,
                ]);

            $body = $response->json();

            // Paystack returns HTTP 200 + status:true on success
            if ($response->successful() && ($body['status'] ?? false) === true) {
                // Look up the bank name from our cached bank list
                $bankName = $this->getBankNameByCode($bankCode);

                return [
                    'success'        => true,
                    'account_name'   => $body['data']['account_name']   ?? null,
                    'account_number' => $body['data']['account_number']  ?? $accountNumber,
                    'bank_code'      => $bankCode,
                    'bank_name'      => $bankName,
                    'message'        => 'Account verified successfully.',
                    'raw'            => $body['data'] ?? null,
                ];
            }

            // Paystack returns HTTP 422 / 400 for invalid account details
            $paystackMessage = $body['message'] ?? 'Could not verify account details.';

            Log::info('Paystack account resolution failed', [
                'account_number'  => $accountNumber,
                'bank_code'       => $bankCode,
                'paystack_status' => $response->status(),
                'paystack_message'=> $paystackMessage,
            ]);

            return [
                'success'        => false,
                'account_name'   => null,
                'account_number' => null,
                'bank_code'      => $bankCode,
                'bank_name'      => null,
                'message'        => $paystackMessage,
                'raw'            => $body,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Paystack connection error during account resolve', [
                'account_number' => $accountNumber,
                'bank_code'      => $bankCode,
                'error'          => $e->getMessage(),
            ]);

            return [
                'success'        => false,
                'account_name'   => null,
                'account_number' => null,
                'bank_code'      => $bankCode,
                'bank_name'      => null,
                'message'        => 'Could not reach Paystack. Please try again shortly.',
                'raw'            => null,
            ];
        }
    }

    /**
     * Get the full list of Nigerian banks from Paystack.
     *
     * Results are cached for 24 hours.
     *
     * @param  bool  $forceRefresh  Skip cache and fetch fresh from Paystack
     * @return array{
     *   success: bool,
     *   banks: array<array{name: string, code: string, slug: string, type: string}>,
     *   message: string
     * }
     */
    public function getBankList(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::BANK_LIST_CACHE_KEY);
        }

        $cached = Cache::get(self::BANK_LIST_CACHE_KEY);
        if ($cached) {
            return [
                'success' => true,
                'banks'   => $cached,
                'message' => 'Banks retrieved successfully.',
            ];
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->timeout(15)
                ->get("{$this->baseUrl}/bank", [
                    'country'    => 'nigeria',
                    'use_cursor' => false,
                    'perPage'    => 200,
                ]);

            $body = $response->json();

            if ($response->successful() && ($body['status'] ?? false) === true) {
                $banks = collect($body['data'] ?? [])
                    ->map(fn ($b) => [
                        'name' => $b['name'],
                        'code' => $b['code'],
                        'slug' => $b['slug'] ?? null,
                        'type' => $b['type'] ?? 'nuban',
                    ])
                    ->sortBy('name')
                    ->values()
                    ->toArray();

                Cache::put(self::BANK_LIST_CACHE_KEY, $banks, self::BANK_LIST_CACHE_TTL);

                return [
                    'success' => true,
                    'banks'   => $banks,
                    'message' => 'Banks retrieved successfully.',
                ];
            }

            return [
                'success' => false,
                'banks'   => [],
                'message' => $body['message'] ?? 'Could not fetch bank list.',
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Paystack connection error fetching bank list', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'banks'   => [],
                'message' => 'Could not reach Paystack. Please try again shortly.',
            ];
        }
    }

    /**
     * Look up a bank name from the cached bank list by code.
     * Falls back to null if the list is not available or code not found.
     */
    private function getBankNameByCode(string $bankCode): ?string
    {
        $banks = Cache::get(self::BANK_LIST_CACHE_KEY);

        if (!$banks) {
            // Fetch and cache silently
            $result = $this->getBankList();
            $banks  = $result['banks'] ?? [];
        }

        $match = collect($banks)->firstWhere('code', $bankCode);
        return $match['name'] ?? null;
    }
}