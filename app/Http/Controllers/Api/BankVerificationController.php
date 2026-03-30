<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

/**
 * BankVerificationController
 *
 * Standalone bank account verification utility.
 * Does NOT save anything to the database.
 * Any authenticated user can call these endpoints.
 *
 * Routes:
 *   GET  /api/banks          → list all banks with their codes (for frontend dropdown)
 *   POST /api/banks/verify   → verify account number + bank code → return account name
 */
class BankVerificationController extends Controller
{
    public function __construct(
        private readonly PaystackService $paystack,
    ) {}


    /**
     * GET /api/banks
     *
     * Returns all Nigerian banks with their Paystack bank codes.
     * Frontend uses this to populate the bank selection dropdown.
     *
     * Results are cached for 24 hours — response is fast after first call.
     *
     * Query params:
     *   refresh=true  → bypass cache and fetch fresh list from Paystack
     */
    public function bankList(Request $request): JsonResponse
    {
        $result = $this->paystack->getBankList(
            forceRefresh: $request->boolean('refresh')
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'data'    => ['banks' => [], 'count' => 0],
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data'    => [
                'banks' => $result['banks'],
                'count' => count($result['banks']),
            ],
        ]);
    }

    /**
     * POST /api/banks/verify
     *
     * Verifies a bank account number with Paystack and returns the
     * account holder name if the details are correct.
     *
     * Does NOT save anything. The frontend should show the returned
     * account name for the user to confirm, then call its own save
     * endpoint separately.
     *
     * Body:
     * {
     *   "account_number": "0123456789",   ← must be exactly 10 digits (Nigerian NUBAN)
     *   "bank_code": "058"                ← from GET /api/banks
     * }
     *
     * Success (200):
     * {
     *   "success": true,
     *   "message": "Account verified successfully.",
     *   "data": {
     *     "account_number": "0123456789",
     *     "account_name": "JOHN ADEWALE DOE",
     *     "bank_code": "058",
     *     "bank_name": "Guaranty Trust Bank"
     *   }
     * }
     *
     * Failure (422):
     * {
     *   "success": false,
     *   "message": "Account verification failed.",
     *   "error": "Could not resolve account name. Check parameters or try again."
     * }
     */
    public function verify(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'account_number' => [
                    'required',
                    'string',
                    'digits:10',   // Nigerian NUBAN is always exactly 10 digits
                ],
                'bank_code' => [
                    'required',
                    'string',
                    'max:10',
                ],
            ]);

            $result = $this->paystack->resolveAccount(
                $validated['account_number'],
                $validated['bank_code']
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account verification failed.',
                    'error'   => $result['message'],
                ], 422);
            }

            Log::info('Bank account verified', [
                'account_number' => $validated['account_number'],
                'bank_code'      => $validated['bank_code'],
                'account_name'   => $result['account_name'],
                'requested_by'   => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Account verified successfully.',
                'data'    => [
                    'account_number' => $result['account_number'],
                    'account_name'   => $result['account_name'],
                    'bank_code'      => $result['bank_code'],
                    'bank_name'      => $result['bank_name'],
                ],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Unexpected error during bank verification: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
            ], 500);
        }
    }
}