<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DividendDeclaration;
use App\Models\DividendPayment;
use App\Models\DividendWorkflowEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DividendPaymentController extends Controller
{
    /**
     * 5.1 List Dividend Payments
     * GET /admin/dividend-declarations/{declaration_id}/payments
     */
    public function index(Request $request, int $declaration_id): JsonResponse
    {
        try {
            $declaration = DividendDeclaration::findOrFail($declaration_id);
            if (!$declaration->isLive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payments are only available for live declarations',
                ], 422);
            }

            $status = $request->query('status');
            if ($status && !in_array($status, ['initiated', 'paid', 'failed', 'disputed', 'reissued'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status filter. Allowed: initiated, paid, failed, disputed, reissued',
                ], 422);
            }

            $query = DividendPayment::with([
                    'entitlement.registerAccount.shareholder',
                    'entitlement.shareClass',
                    'bankMandate',
                ])
                ->whereHas('entitlement', function ($q) use ($declaration_id) {
                    $q->where('dividend_declaration_id', $declaration_id);
                })
                ->orderByDesc('created_at');

            if ($status) {
                $query->where('status', $status);
            }

            $perPage = (int) $request->query('per_page', 50);
            $payments = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $payments,
                'message' => 'Dividend payments retrieved successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend declaration not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving dividend payments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 5.2 Reissue Dividend Payment
     * POST /admin/dividend-payments/{payment_id}/reissue
     */
    public function reissue(Request $request, int $payment_id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|max:255',
            ]);

            $payment = DividendPayment::with(['entitlement.declaration'])
                ->findOrFail($payment_id);

            $declaration = $payment->entitlement?->declaration;
            if (!$declaration || !$declaration->isLive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reissue is only allowed for live declarations',
                ], 422);
            }

            if (!in_array($payment->status, ['failed', 'disputed'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed or disputed payments can be reissued',
                ], 422);
            }

            DB::beginTransaction();

            try {
                $newPayment = DividendPayment::create([
                    'dividend_payment_no' => $this->generatePaymentNo(),
                    'entitlement_id' => $payment->entitlement_id,
                    'payout_mode' => $payment->payout_mode,
                    'bank_mandate_id' => $payment->bank_mandate_id,
                    'paid_ref' => $this->generatePaymentRef(),
                    'status' => 'initiated',
                    'reissued_from_id' => $payment->id,
                    'reissue_reason' => $validated['reason'],
                    'created_by' => $request->user()->id,
                ]);

                $payment->update([
                    'status' => 'reissued',
                ]);

                DividendWorkflowEvent::create([
                    'dividend_declaration_id' => $declaration->id,
                    'event_type' => 'PAYMENT_REISSUED',
                    'actor_id' => $request->user()->id,
                    'note' => 'Payment reissued: ' . $validated['reason'] . ' (old #' . $payment->id . ', new #' . $newPayment->id . ')',
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Dividend payment reissued successfully',
                    'data' => [
                        'original_payment' => $payment->fresh(),
                        'new_payment' => $newPayment,
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dividend payment not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error reissuing dividend payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function generatePaymentRef(): string
    {
        return 'DP-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    }

    private function generatePaymentNo(): string
    {
        return 'DPN-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    }
}
