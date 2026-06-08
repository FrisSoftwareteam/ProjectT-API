<?php

namespace App\Observers;

use App\Models\DividendPayment;
use App\Services\AdminNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DividendPaymentObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly AdminNotificationService $adminNotificationService
    ) {}

    public function updated(DividendPayment $payment): void
    {
        if (! $payment->wasChanged('status') || $payment->status !== 'failed') {
            return;
        }

        $declarationId = $payment->entitlement?->dividend_declaration_id;

        $this->adminNotificationService->sendToRoles(
            ['Finance', 'Accounts', 'Reconciliation', 'Internal Audit', 'Super Admin'],
            'DIVIDEND_PAYMENT_FAILED',
            'Dividend payment failed',
            "Dividend payment {$payment->dividend_payment_no} failed and requires review.",
            'dividend_payment',
            $payment->id,
            $payment->dividend_payment_no,
            $declarationId
                ? "/admin/dividend-declarations/{$declarationId}/payments"
                : '/admin/dividend-payments',
            null
        );
    }
}
