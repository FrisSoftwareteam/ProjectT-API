<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\NibssPayService;
use App\Services\AdminNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NibssController extends Controller
{
    public function __construct(
        private NibssPayService $nibssPayService,
        private AdminNotificationService $adminNotificationService
    ) {}

    public function getAccounts(Request $request)
    {
        $validated = $request->validate([
            // Add validation rules matching the NIBSS PAY payment payload here
        ]);

        $result = $this->nibssPayService->getAccounts($validated);

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['data'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    public function getBankList()
    {
        $result = $this->nibssPayService->getBankList();

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['data'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    public function createSchedule(Request $request)
    {
       
        $validated = $request->validate([
            // Add validation rules matching the NIBSS PAY payment payload here
            "title" => "string|required",
            "debitBankCode" => "string|required",
            "debitAccountNumber" => "string|required",
            "debitDescription" => "string|required",
            "paymentMode" => "string|required",
            "scheduleType" => "string|required|in:Csv,Neft,Nip"
        ]);

        $validated['referenceNo'] = now()->format('YmdHis') . random_int(100000, 999999);

        Log::info('Create Schedule Request Payload', ['payload' => $validated]);

        $result = $this->nibssPayService->createSchedule($validated);
        $this->notifyFailure($result, 'schedule creation', $request->user()?->id);

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['data'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }   

    public function getSchedules()
    {
        $result = $this->nibssPayService->getSchedule();

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['data'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    public function postAccounts(Request $request)
    {
        $validated = $request->validate([
            // Add validation rules matching the NIBSS PAY payment payload here
        ]);

        $result = $this->nibssPayService->initiatePayment($validated);
        $this->notifyFailure($result, 'payment initiation', $request->user()?->id);

        return response()->json([
            'success' => $result['success'],
            'data'    => $result['data'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    private function notifyFailure(array $result, string $operation, ?int $actorId): void
    {
        if ($result['success']) {
            return;
        }

        $this->adminNotificationService->sendToRoles(
            ['Finance', 'Accounts', 'Reconciliation', 'Internal Audit', 'Super Admin'],
            'NIBSS_OPERATION_FAILED',
            'NIBSS operation failed',
            "NIBSS {$operation} failed. Review the NIBSS operation logs.",
            'nibss_operation',
            now()->timestamp,
            'NIBSS operation',
            '/admin/nibss/schedules',
            $actorId,
            [$actorId],
            true
        );
    }
}
