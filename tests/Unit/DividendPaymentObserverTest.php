<?php

namespace Tests\Unit;

use App\Models\DividendEntitlement;
use App\Models\DividendPayment;
use App\Observers\DividendPaymentObserver;
use App\Services\AdminNotificationService;
use Mockery;
use PHPUnit\Framework\TestCase;

class DividendPaymentObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_failed_status_transition_dispatches_internal_notification(): void
    {
        $service = Mockery::mock(AdminNotificationService::class);
        $service->shouldReceive('sendToRoles')->once();

        $payment = new DividendPayment([
            'dividend_payment_no' => 'DPN-TEST-1',
            'status' => 'initiated',
        ]);
        $payment->id = 1;
        $payment->syncOriginal();
        $payment->status = 'failed';
        $payment->syncChanges();
        $payment->setRelation('entitlement', new DividendEntitlement([
            'dividend_declaration_id' => 10,
        ]));

        (new DividendPaymentObserver($service))->updated($payment);

        $this->assertTrue(true);
    }
}
