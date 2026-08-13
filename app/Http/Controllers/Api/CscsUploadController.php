<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CscsUploadRequest;
use App\Jobs\PostCscsBatchJob;
use App\Jobs\ProcessCscsImportJob;
use App\Models\CscsApprovalPolicy;
use App\Models\CscsSecurityMapping;
use App\Models\CscsUploadBatch;
use App\Models\CscsUploadRow;
use App\Services\AdminNotificationService;
use App\Services\CscsImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CscsUploadController extends Controller
{
    public function __construct(
        private readonly CscsImportService $service,
        private readonly AdminNotificationService $notifications
    ) {}

    public function import(CscsUploadRequest $request): JsonResponse
    {
        try {
            $result = $this->service->stageImport(
                $request->file('files'),
                (int) $request->validated('register_id'),
                $request->user()?->id,
                $request->validated('description'),
                $request->validated('business_reference')
            );
            ProcessCscsImportJob::dispatch((int) $result['batch_id']);

            return $this->success('CSCS files accepted for processing', $result, 202);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Unable to stage CSCS upload', ['actor_id' => $request->user()?->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Unable to process the CSCS upload safely.'], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'register_id' => ['nullable', 'integer', 'exists:registers,id'],
            'business_reference' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('cscs.max_page_size', 100)],
        ]);
        $query = CscsUploadBatch::query()->with('register')->withCount(['rows', 'rows as unresolved_exceptions_count' => fn ($q) => $q
            ->where('file_type', 'movement')->whereNotIn('resolution_status', ['READY', 'CONFIRMED_REPLAY', 'RULE_EXCLUDED', 'POSTED'])]);
        $query->when($validated['status'] ?? null, fn ($q, $v) => $q->where('workflow_status', $v));
        $query->when($validated['register_id'] ?? null, fn ($q, $v) => $q->where('register_id', $v));
        $query->when($validated['business_reference'] ?? null, fn ($q, $v) => $q->where('business_reference', 'like', '%'.$v.'%'));

        return response()->json($query->latest('id')->paginate($validated['per_page'] ?? 15));
    }

    public function show(Request $request, int $batchId): JsonResponse
    {
        $batch = CscsUploadBatch::withCount('rows')->with(['approvalActions.actor', 'events.actor', 'register'])->findOrFail($batchId);

        return response()->json(['data' => $this->batchPayload($batch, $request)]);
    }

    public function rows(Request $request, int $batchId): JsonResponse
    {
        $this->batch($batchId);
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'identifier' => ['nullable', 'string', 'max:100'],
            'tran_no' => ['nullable', 'string', 'max:32'],
            'security_code' => ['nullable', 'string', 'max:20'],
            'sign' => ['nullable', Rule::in(['+', '-'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('cscs.max_page_size', 100)],
        ]);
        $query = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')->orderBy('id');
        $query->when($validated['status'] ?? null, fn ($q, $v) => $q->where('resolution_status', $v));
        $query->when($validated['identifier'] ?? null, fn ($q, $v) => $q->where('identifier_value', 'like', '%'.$v.'%'));
        $query->when($validated['tran_no'] ?? null, fn ($q, $v) => $q->where('tran_no', $v));
        $query->when($validated['security_code'] ?? null, fn ($q, $v) => $q->where('sec_code', strtoupper($v)));
        $query->when($validated['sign'] ?? null, fn ($q, $v) => $q->where('sign', $v));

        return $this->paginatedWithPrecision($query->paginate($validated['per_page'] ?? 50), $batchId);
    }

    public function row(int $batchId, int $rowId): JsonResponse
    {
        return response()->json([
            'data' => CscsUploadRow::where('batch_id', $batchId)->findOrFail($rowId),
            'meta' => $this->precisionMeta($batchId),
        ]);
    }

    public function masterRecords(Request $request, int $batchId): JsonResponse
    {
        $this->batch($batchId);
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('cscs.max_page_size', 100)]]);

        return $this->paginatedWithPrecision(
            CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'master')
                ->orderBy('id')->paginate($validated['per_page'] ?? 50),
            $batchId
        );
    }

    public function transactions(Request $request, int $batchId): JsonResponse
    {
        $this->batch($batchId);
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $groups = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')->whereNotNull('tran_no')
            ->orderBy('tran_no')->orderBy('id')->get()->groupBy('tran_no')->map(fn ($rows, $number) => $this->transactionPayload((string) $number, $rows))->values();
        $perPage = $validated['per_page'] ?? 50;
        $page = $validated['page'] ?? 1;
        $paginator = new LengthAwarePaginator($groups->forPage($page, $perPage)->values(), $groups->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return $this->paginatedWithPrecision($paginator, $batchId);
    }

    public function transaction(int $batchId, string $transactionNumber): JsonResponse
    {
        $rows = CscsUploadRow::where('batch_id', $batchId)->where('tran_no', $transactionNumber)->orderBy('id')->get();
        abort_if($rows->isEmpty(), 404);

        return response()->json([
            'data' => $this->transactionPayload($transactionNumber, $rows),
            'meta' => $this->precisionMeta($batchId),
        ]);
    }

    public function accountEffects(int $batchId): JsonResponse
    {
        $this->batch($batchId);

        return response()->json([
            'data' => $this->service->accountEffects($batchId),
            'meta' => $this->precisionMeta($batchId),
        ]);
    }

    public function preview(Request $request, int $batchId): JsonResponse
    {
        $batch = CscsUploadBatch::with(['approvalActions.actor', 'register'])->findOrFail($batchId);

        return response()->json(['data' => [
            'batch' => $this->batchPayload($batch, $request),
            'account_effects' => $this->service->accountEffects($batchId),
            'security_mappings' => CscsSecurityMapping::whereIn('security_code', CscsUploadRow::where('batch_id', $batchId)->whereNotNull('sec_code')->distinct()->pluck('sec_code'))->get(),
        ]]);
    }

    public function exceptions(Request $request, int $batchId): JsonResponse
    {
        $this->batch($batchId);
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'exception_code' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('cscs.max_page_size', 100)],
        ]);
        $query = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')
            ->where(fn ($q) => $q->whereNotNull('exception_code')->orWhereIn('resolution_status', ['RULE_EXCLUDED', 'CONFIRMED_REPLAY']))->orderBy('id');
        $query->when($validated['status'] ?? null, fn ($q, $v) => $q->where('resolution_status', $v));
        $query->when($validated['exception_code'] ?? null, fn ($q, $v) => $q->where('exception_code', $v));

        return $this->paginatedWithPrecision($query->paginate($validated['per_page'] ?? 50), $batchId);
    }

    public function resolveException(Request $request, int $batchId, int $exceptionId): JsonResponse
    {
        $validated = $request->validate([
            'resolution_type' => ['required', Rule::in(['MAP_ACCOUNT', 'RULE_EXCLUDED', 'CONFIRM_REPLAY'])],
            'register_account_id' => ['required_if:resolution_type,MAP_ACCOUNT', 'nullable', 'integer', 'exists:shareholder_register_accounts,id'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->success('Exception resolution recorded; revalidation is required', $this->service->resolveException($batchId, $exceptionId, (int) $request->user()->id, $validated));
    }

    public function revalidate(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return $this->success('CSCS batch reconciled', $this->service->reconcile($batchId, (int) $request->user()->id, $validated['comment'] ?? null));
    }

    public function reconciliation(int $batchId): JsonResponse
    {
        $batch = $this->batch($batchId);

        return response()->json(['data' => ['status' => $batch->workflow_status, 'snapshot_hash' => $batch->snapshot_hash, 'reconciliation' => $batch->reconciliation, 'risk_flags' => $batch->risk_flags]]);
    }

    public function submit(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $data = $this->service->submit($batchId, (int) $request->user()->id, $validated['comment'] ?? null);
        $this->notify(['Reconciliation', 'Admin', 'Super Admin'], 'CSCS_APPROVAL_REQUIRED', 'CSCS approval required', $batchId, $request->user()->id);

        return $this->success('CSCS batch submitted for approval', $data);
    }

    public function raiseQuery(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'min:10', 'max:1000'],
            'transaction_numbers' => ['nullable', 'array', 'max:100'],
            'transaction_numbers.*' => ['string', 'max:32'],
            'row_ids' => ['nullable', 'array', 'max:100'],
            'row_ids.*' => ['integer'],
        ]);
        $context = ['transaction_numbers' => $validated['transaction_numbers'] ?? [], 'row_ids' => $validated['row_ids'] ?? []];
        $data = $this->service->raiseQuery($batchId, (int) $request->user()->id, $validated['comment'], $context);
        $this->notify([], 'CSCS_QUERY_RAISED', 'Query raised on CSCS batch', $batchId, $request->user()->id, [$this->batch($batchId)->uploaded_by]);

        return $this->success('Query raised', $data);
    }

    public function respondToQuery(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->success('Query response recorded; reconcile and resubmit the batch', $this->service->respondToQuery($batchId, (int) $request->user()->id, $validated['comment']));
    }

    public function approve(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $data = $this->service->approve($batchId, $request->user(), $validated['comment'] ?? null);
        $this->notify(['Reconciliation', 'Internal Audit', 'Compliance', 'Admin', 'Super Admin'], 'CSCS_APPROVED', 'CSCS batch approved', $batchId, $request->user()->id);

        return $this->success('Approval recorded', $data);
    }

    public function reject(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->success('CSCS batch rejected', $this->service->reject($batchId, $request->user(), $validated['comment']));
    }

    public function cancel(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->success('CSCS batch cancelled', $this->service->cancel($batchId, (int) $request->user()->id, $validated['comment']));
    }

    public function post(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $data = $this->service->queueForPosting($batchId, $request->user(), $validated['comment'] ?? null);
        PostCscsBatchJob::dispatch($batchId, (int) $request->user()->id, $validated['comment'] ?? null);

        return $this->success('CSCS batch accepted for controlled posting', $data, 202);
    }

    public function postingStatus(int $batchId): JsonResponse
    {
        $batch = $this->batch($batchId);

        return response()->json(['data' => [
            'batch_id' => $batch->id,
            'status' => $batch->workflow_status,
            'posting_started_at' => $batch->posting_started_at,
            'posted_at' => $batch->posted_at,
            'failure_reason' => $batch->failure_reason,
            'posted_rows' => CscsUploadRow::where('batch_id', $batchId)->where('status', 'posted')->count(),
        ]]);
    }

    public function createReversal(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'transaction_numbers' => ['nullable', 'array', 'max:1000'],
            'transaction_numbers.*' => ['string', 'max:32'],
        ]);
        $data = $this->service->createReversal(
            $batchId,
            (int) $request->user()->id,
            $validated['reason'],
            $validated['effective_date'],
            $validated['transaction_numbers'] ?? []
        );

        return $this->success('Compensating CSCS batch created for reconciliation and approval', $data, 201);
    }

    public function relatedBatches(int $batchId): JsonResponse
    {
        $batch = CscsUploadBatch::with(['sourceBatch', 'relatedBatches'])->findOrFail($batchId);

        return response()->json(['data' => [
            'source_batch' => $batch->sourceBatch,
            'related_batches' => $batch->relatedBatches,
        ]]);
    }

    public function events(int $batchId): JsonResponse
    {
        $batch = CscsUploadBatch::with(['events.actor'])->findOrFail($batchId);

        return response()->json(['data' => $batch->events]);
    }

    public function approvals(int $batchId): JsonResponse
    {
        $batch = CscsUploadBatch::with(['approvalActions.actor'])->findOrFail($batchId);

        return response()->json(['data' => $batch->approvalActions]);
    }

    public function files(int $batchId): JsonResponse
    {
        return response()->json(['data' => $this->batch($batchId)->uploaded_files ?? []]);
    }

    public function downloadFile(int $batchId, int $fileIndex)
    {
        $files = $this->batch($batchId)->uploaded_files ?? [];
        abort_unless(isset($files[$fileIndex]), 404);
        abort_unless(Storage::exists($files[$fileIndex]['path']), 404);

        return Storage::download($files[$fileIndex]['path'], basename($files[$fileIndex]['name']));
    }

    public function export(Request $request, int $batchId)
    {
        $batch = $this->batch($batchId);
        $type = $request->validate(['type' => ['nullable', Rule::in(['rows', 'exceptions', 'reconciliation', 'preview', 'posting'])]])['type'] ?? 'rows';
        $rows = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement');
        if ($type === 'exceptions') {
            $rows->whereNotNull('exception_code');
        } elseif ($type === 'posting') {
            $rows->where('status', 'posted');
        }
        $filename = "cscs_{$batchId}_{$type}.csv";

        return response()->streamDownload(function () use ($rows, $batch, $type) {
            $out = fopen('php://output', 'wb');
            if ($type === 'reconciliation') {
                fputcsv($out, ['metric', 'value']);
                foreach ($batch->reconciliation ?? [] as $key => $value) {
                    if (! is_array($value)) {
                        fputcsv($out, [$key, $value]);
                    }
                }
            } else {
                fputcsv($out, ['row_id', 'transaction_number', 'sequence', 'date', 'security_code', 'identifier_type', 'identifier', 'sign', 'quantity', 'resolution_status', 'exception_code', 'before', 'delta', 'after', 'actual_before', 'actual_after']);
                foreach ($rows->orderBy('id')->cursor() as $row) {
                    fputcsv($out, [$row->id, $row->tran_no, $row->tran_seq, optional($row->trade_date)->format('Y-m-d'), $row->sec_code, $row->identifier_type, $row->identifier_value, $row->sign, $row->volume, $row->resolution_status, $row->exception_code, $row->proposed_before_qty, $row->proposed_delta_qty, $row->proposed_after_qty, $row->actual_before_qty, $row->actual_after_qty]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function securityMappings(): JsonResponse
    {
        return response()->json(['data' => CscsSecurityMapping::with(['register', 'shareClass'])->orderBy('security_code')->get()]);
    }

    public function storeSecurityMapping(Request $request): JsonResponse
    {
        $validated = $this->validateMapping($request);
        $mapping = CscsSecurityMapping::create($validated + ['security_code' => strtoupper($validated['security_code']), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return $this->success('CSCS security mapping created', $mapping, 201);
    }

    public function updateSecurityMapping(Request $request, int $mappingId): JsonResponse
    {
        $mapping = CscsSecurityMapping::findOrFail($mappingId);
        $validated = $this->validateMapping($request, $mappingId);
        $mapping->update($validated + ['security_code' => strtoupper($validated['security_code']), 'updated_by' => $request->user()->id]);

        return $this->success('CSCS security mapping updated', $mapping->fresh());
    }

    public function deactivateSecurityMapping(Request $request, int $mappingId): JsonResponse
    {
        $mapping = CscsSecurityMapping::findOrFail($mappingId);
        $mapping->update(['is_active' => false, 'updated_by' => $request->user()->id]);

        return $this->success('CSCS security mapping deactivated', $mapping->fresh());
    }

    public function approvalPolicy(): JsonResponse
    {
        return response()->json(['data' => CscsApprovalPolicy::where('is_active', true)->first()]);
    }

    public function updateApprovalPolicy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'checker_roles' => ['nullable', 'array', 'max:20'],
            'checker_roles.*' => ['string', 'max:100', 'exists:roles,name'],
            'additional_approval_quantity' => ['nullable', 'decimal:0,6', 'gt:0'],
            'additional_approval_roles' => ['nullable', 'array', 'max:20'],
            'additional_approval_roles.*' => ['string', 'max:100', 'exists:roles,name'],
            'checker_can_post' => ['required', 'boolean'],
        ]);
        $policy = CscsApprovalPolicy::where('is_active', true)->first();
        $policy ??= new CscsApprovalPolicy(['is_active' => true]);
        $policy->fill($validated + ['updated_by' => $request->user()->id])->save();

        return $this->success('CSCS approval policy updated', $policy->fresh());
    }

    private function validateMapping(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['security_code' => strtoupper(trim((string) $request->input('security_code')))]);
        $validated = $request->validate([
            'security_code' => ['required', 'string', 'max:20', Rule::unique('cscs_security_mappings', 'security_code')->ignore($ignoreId)],
            'register_id' => ['required', 'integer', 'exists:registers,id'],
            'share_class_id' => ['required', 'integer', 'exists:share_classes,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $belongs = \App\Models\ShareClass::whereKey($validated['share_class_id'])->where('register_id', $validated['register_id'])->exists();
        if (! $belongs) {
            throw ValidationException::withMessages(['share_class_id' => ['The share class does not belong to the selected register.']]);
        }

        return $validated;
    }

    private function transactionPayload(string $number, $rows): array
    {
        $debit = '0.000000';
        $credit = '0.000000';
        foreach ($rows as $row) {
            if ($row->sign === '-') {
                $debit = bcadd($debit, (string) $row->volume, 6);
            } elseif ($row->sign === '+') {
                $credit = bcadd($credit, (string) $row->volume, 6);
            }
        }

        return [
            'transaction_number' => $number,
            'trade_date' => optional($rows->first()->trade_date)->format('Y-m-d'),
            'security_code' => $rows->first()->sec_code,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'net_total' => bcsub($credit, $debit, 6),
            'leg_count' => $rows->count(),
            'is_balanced' => $rows->count() === 2 && bccomp($credit, $debit, 6) === 0,
            'status' => $rows->pluck('resolution_status')->unique()->values(),
            'legs' => $rows,
        ];
    }

    private function batchPayload(CscsUploadBatch $batch, Request $request): array
    {
        $user = $request->user();
        $status = $batch->workflow_status;
        $isMaker = $user && (int) $batch->uploaded_by === (int) $user->id;
        $actions = ['view'];
        if ($isMaker && $user->can('cscs.reconcile') && in_array($status, ['DRAFT_REVIEW', 'QUERY_RAISED', 'STALE'], true)) {
            $actions = array_merge($actions, ['resolve', 'revalidate', 'cancel']);
        }
        if ($isMaker && $user->can('cscs.submit') && $status === 'RECONCILED') {
            $actions[] = 'submit';
        }
        if (! $isMaker && $user?->can('cscs.approve') && $status === 'PENDING_APPROVAL') {
            $actions = array_merge($actions, ['query', 'approve', 'reject']);
        }
        if (! $isMaker && $user?->can('cscs.post') && in_array($status, ['APPROVED_AWAITING_POST', 'POSTING_FAILED'], true)) {
            $actions[] = 'post';
        }
        $payload = $batch->toArray();
        $payload['allowed_actions'] = array_values(array_unique($actions));

        return $payload;
    }

    private function paginatedWithPrecision(LengthAwarePaginator $paginator, int $batchId): JsonResponse
    {
        $payload = $paginator->toArray();
        $payload['meta'] = $this->precisionMeta($batchId);

        return response()->json($payload);
    }

    /** @return array{unit_precision: array{type: string, decimal_places: int}} */
    private function precisionMeta(int $batchId): array
    {
        $batch = CscsUploadBatch::with('register')->findOrFail($batchId);

        return ['unit_precision' => $batch->register->unit_precision];
    }

    private function batch(int $batchId): CscsUploadBatch
    {
        return CscsUploadBatch::findOrFail($batchId);
    }

    private function notify(array $roles, string $event, string $title, int $batchId, ?int $actorId, array $users = []): void
    {
        $this->notifications->sendToRoles($roles, $event, $title, "CSCS batch #{$batchId} requires attention.", 'cscs_upload_batch', $batchId, "CSCS batch #{$batchId}", "/cscs/uploads/{$batchId}", $actorId, $users);
    }

    private function success(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
