<?php

namespace App\Http\Controllers\Api;

use App\Exports\CscsActivityExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CscsUploadRequest;
use App\Jobs\PostCscsBatchJob;
use App\Jobs\ProcessCscsImportJob;
use App\Models\CscsApprovalPolicy;
use App\Models\CscsSecurityMapping;
use App\Models\CscsUploadBatch;
use App\Models\CscsUploadRow;
use App\Models\CscsWorkflowEvent;
use App\Models\ShareClass;
use App\Models\ShareholderRegisterAccount;
use App\Services\AdminNotificationService;
use App\Services\CscsImportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

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
        $batch = CscsUploadBatch::withCount('rows')->with($this->batchReviewRelations())->findOrFail($batchId);

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
        $row = CscsUploadRow::where('batch_id', $batchId)->findOrFail($rowId);
        $accounts = $this->accountDisplayMap(collect([$row->proposed_sra_id]));
        $history = $this->exceptionHistory($batchId)->get($row->id, collect());

        return response()->json([
            'data' => $this->exceptionPayload($row, $accounts, $history),
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
        $flaggedFilter = $request->query('is_flagged');
        if (is_string($flaggedFilter) && in_array(strtolower($flaggedFilter), ['true', 'false'], true)) {
            $flaggedFilter = strtolower($flaggedFilter) === 'true';
        }
        $request->merge(array_filter([
            'search' => $request->filled('search') ? trim((string) $request->query('search')) : null,
            'balance_status' => $request->filled('balance_status') ? strtoupper((string) $request->query('balance_status')) : null,
            'resolution_status' => $request->filled('resolution_status') ? strtoupper((string) $request->query('resolution_status')) : null,
            'security_code' => $request->filled('security_code') ? strtoupper((string) $request->query('security_code')) : null,
            'is_flagged' => $request->has('is_flagged') ? $flaggedFilter : null,
        ], fn ($value) => $value !== null));
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'balance_status' => ['nullable', Rule::in(['BALANCED', 'UNBALANCED'])],
            'is_flagged' => ['nullable', 'boolean'],
            'resolution_status' => ['nullable', 'string', 'max:40'],
            'security_code' => ['nullable', 'string', 'max:20'],
            'trade_date_from' => ['nullable', 'date_format:Y-m-d'],
            'trade_date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        if (array_key_exists('is_flagged', $validated) && $validated['is_flagged'] !== null) {
            $validated['is_flagged'] = filter_var($validated['is_flagged'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($validated['trade_date_from'], $validated['trade_date_to'])
            && $validated['trade_date_to'] < $validated['trade_date_from']) {
            throw ValidationException::withMessages([
                'trade_date_to' => ['The trade date to must be on or after the trade date from.'],
            ]);
        }

        $groups = $this->transactionGroupsQuery($batchId);
        $this->applyTransactionFilters($groups, $batchId, $validated);
        $perPage = $validated['per_page'] ?? 50;
        $page = $validated['page'] ?? 1;
        $paginator = $groups->orderBy('tran_no')->paginate($perPage, ['*'], 'page', $page);
        $paginator->appends($request->query());
        $transactionNumbers = $paginator->getCollection()->pluck('tran_no')->map(fn ($number) => (string) $number);
        $rows = CscsUploadRow::where('batch_id', $batchId)->whereIn('tran_no', $transactionNumbers)
            ->orderBy('tran_no')->orderBy('id')->get()->groupBy('tran_no');
        $accounts = $this->accountDisplayMap($rows->flatten()->pluck('proposed_sra_id'));
        $paginator->setCollection($transactionNumbers->map(
            fn (string $number) => $this->transactionPayload($number, $rows->get($number, collect()), $accounts)
        ));

        $counts = DB::query()->fromSub($this->transactionGroupsQuery($batchId), 'transaction_groups')
            ->selectRaw('COUNT(*) as all_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_balanced = 1 THEN 1 ELSE 0 END), 0) as balanced_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_balanced = 0 THEN 1 ELSE 0 END), 0) as unbalanced_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_flagged = 1 THEN 1 ELSE 0 END), 0) as flagged_count')
            ->first();
        $appliedFilters = collect($validated)->except(['page', 'per_page'])
            ->filter(fn ($value) => $value !== null && $value !== '')->all();

        return $this->paginatedWithPrecision($paginator, $batchId, [
            'transaction_counts' => [
                'all' => (int) ($counts->all_count ?? 0),
                'balanced' => (int) ($counts->balanced_count ?? 0),
                'unbalanced' => (int) ($counts->unbalanced_count ?? 0),
                'flagged' => (int) ($counts->flagged_count ?? 0),
            ],
            'applied_filters' => $appliedFilters,
        ]);
    }

    public function transaction(int $batchId, string $transactionNumber): JsonResponse
    {
        $rows = CscsUploadRow::where('batch_id', $batchId)->where('tran_no', $transactionNumber)->orderBy('id')->get();
        abort_if($rows->isEmpty(), 404);
        $accounts = $this->accountDisplayMap($rows->pluck('proposed_sra_id'));

        return response()->json([
            'data' => $this->transactionPayload($transactionNumber, $rows, $accounts),
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
        $batch = CscsUploadBatch::with($this->batchReviewRelations())->findOrFail($batchId);
        $accountEffects = $this->service->accountEffects($batchId);
        $securityMappings = CscsSecurityMapping::with(['register', 'shareClass'])
            ->whereIn('security_code', CscsUploadRow::where('batch_id', $batchId)->whereNotNull('sec_code')->distinct()->pluck('sec_code'))
            ->get();

        return response()->json(['data' => [
            'batch' => $this->batchPayload($batch, $request),
            'account_effects' => $accountEffects,
            'proposed_new_accounts' => $accountEffects->where('is_new_account', true)->values(),
            'security_mappings' => $securityMappings,
            'review_summary' => $this->reviewSummary($batch, $accountEffects, $securityMappings),
            'approval_timeline' => $batch->events->values(),
            'comments' => $batch->events->whereNotNull('comment')->values(),
        ]]);
    }

    public function exceptions(Request $request, int $batchId): JsonResponse
    {
        $this->batch($batchId);
        if (! $request->filled('status') && $request->filled('resolution_status')) {
            $request->merge(['status' => strtoupper((string) $request->query('resolution_status'))]);
        }
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'resolution_status' => ['nullable', 'string', 'max:40'],
            'exception_code' => ['nullable', 'string', 'max:60'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('cscs.max_page_size', 100)],
        ]);
        $baseQuery = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')
            ->where(fn ($q) => $q->whereNotNull('exception_code')
                ->orWhereIn('resolution_status', ['RULE_EXCLUDED', 'CONFIRMED_REPLAY'])
                ->orWhereNotNull('resolved_at'));
        $allExceptionRows = (clone $baseQuery)->orderBy('id')->get();
        $query = (clone $baseQuery)->orderBy('id');
        $query->when($validated['status'] ?? null, fn ($q, $v) => $q->where('resolution_status', $v));
        $query->when($validated['exception_code'] ?? null, fn ($q, $v) => $q->where('exception_code', $v));
        $query->when($validated['search'] ?? null, function ($q, $search): void {
            $q->where(function ($nested) use ($search): void {
                $like = '%'.$search.'%';
                $nested->where('tran_no', 'like', $like)
                    ->orWhere('identifier_value', 'like', $like)
                    ->orWhere('exception_code', 'like', $like)
                    ->orWhere('error_message', 'like', $like)
                    ->orWhere('row_number', $search);
            });
        });

        $paginator = $query->paginate($validated['per_page'] ?? 50);
        $accounts = $this->accountDisplayMap($paginator->getCollection()->pluck('proposed_sra_id'));
        $history = $this->exceptionHistory($batchId);
        $paginator->setCollection($paginator->getCollection()->map(
            fn (CscsUploadRow $row) => $this->exceptionPayload($row, $accounts, $history->get($row->id, collect()))
        ));

        return $this->paginatedWithPrecision($paginator, $batchId, [
            'exception_counts' => $this->exceptionCounts($allExceptionRows),
            'applied_filters' => collect($validated)->except('per_page')->filter(fn ($value) => $value !== null && $value !== '')->all(),
        ]);
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

    public function postingReadiness(int $batchId): JsonResponse
    {
        return response()->json(['data' => $this->service->postingReadiness($batchId)]);
    }

    public function verificationSummary(int $batchId): JsonResponse
    {
        $batch = CscsUploadBatch::with('register')->findOrFail($batchId);
        $postedRows = CscsUploadRow::where('batch_id', $batchId)
            ->where('resolution_status', 'POSTED')
            ->get();
        $movementRows = CscsUploadRow::where('batch_id', $batchId)->where('file_type', 'movement')->get();
        $reconciliation = $batch->reconciliation ?? [];
        $verification = $reconciliation['post_verification'] ?? null;
        $approvedAffectedAccounts = $movementRows->whereIn('resolution_status', ['POSTED', 'READY'])
            ->map(fn (CscsUploadRow $row) => $row->proposed_sra_id ?: 'new:'.$row->identifier_value)
            ->filter()->unique()->count();
        $actualAffectedAccounts = $postedRows->pluck('sra_id')->filter()->unique()->count();
        $approvedNewAccounts = $movementRows->where('match_method', 'proposed_new_account')->pluck('identifier_value')->filter()->unique()->count();
        $actualNewAccounts = $postedRows->where('match_method', 'proposed_new_account')->pluck('sra_id')->filter()->unique()->count();
        $resolvedExceptions = (int) data_get($reconciliation, 'replay_rows', 0) + (int) data_get($reconciliation, 'excluded_rows', 0);
        $replayTransactions = $movementRows->where('resolution_status', 'CONFIRMED_REPLAY')->pluck('tran_no')->filter()->unique()->count();
        $recordsApproved = (int) data_get($reconciliation, 'ready_rows', 0);
        $actualDebit = data_get($verification, 'total_debit', '0.000000');
        $actualCredit = data_get($verification, 'total_credit', '0.000000');
        $actualNet = data_get($verification, 'net_movement', '0.000000');
        $comparison = [
            $this->verificationComparison('total_debit', 'Total Debit Units', data_get($reconciliation, 'total_debit', '0.000000'), $actualDebit),
            $this->verificationComparison('total_credit', 'Total Credit Units', data_get($reconciliation, 'total_credit', '0.000000'), $actualCredit),
            $this->verificationComparison('net_movement', 'Net Movement', data_get($reconciliation, 'net_movement', '0.000000'), $actualNet, 'BALANCED'),
            $this->verificationComparison('shareholder_records_updated', 'Shareholder Records Updated', $approvedAffectedAccounts, $actualAffectedAccounts),
            $this->verificationComparison('new_accounts_created', 'New Accounts Created', $approvedNewAccounts, $actualNewAccounts),
            $this->verificationComparison('exceptions_resolved', 'Exceptions Resolved', $resolvedExceptions, $resolvedExceptions),
            $this->verificationComparison('replay_transactions', 'Replay Transactions', $replayTransactions, $replayTransactions, 'CLEAR'),
        ];

        return response()->json(['data' => [
            'batch_id' => $batch->id,
            'status' => $batch->workflow_status,
            'verification_status' => data_get($verification, 'status', $batch->workflow_status === 'POSTED' ? 'PENDING' : 'NOT_POSTED'),
            'posted_at' => $batch->posted_at,
            'metrics' => [
                'records_posted' => $postedRows->count(),
                'transaction_groups_posted' => $postedRows->pluck('tran_no')->filter()->unique()->count(),
                'failed_rows' => CscsUploadRow::where('batch_id', $batchId)->where('status', 'posting_failed')->count(),
                'duplicate_prevention_blocks' => $replayTransactions,
                'total_debit' => $actualDebit,
                'total_credit' => $actualCredit,
                'net_movement' => $actualNet,
                'shareholder_records_updated' => $actualAffectedAccounts,
                'new_accounts_created' => $actualNewAccounts,
                'exceptions_resolved' => $resolvedExceptions,
                'replay_transactions' => $replayTransactions,
            ],
            'approved_totals' => [
                'total_debit' => data_get($reconciliation, 'total_debit'),
                'total_credit' => data_get($reconciliation, 'total_credit'),
                'net_movement' => data_get($reconciliation, 'net_movement'),
                'ready_rows' => $recordsApproved,
                'shareholder_records_updated' => $approvedAffectedAccounts,
                'new_accounts_created' => $approvedNewAccounts,
                'exceptions_resolved' => $resolvedExceptions,
                'replay_transactions' => $replayTransactions,
            ],
            'comparison' => $comparison,
            'checks' => data_get($verification, 'checks', []),
            'all_checks_passed' => data_get($verification, 'status') === 'VERIFIED'
                && ! in_array(false, data_get($verification, 'checks', []), true),
            'verified_at' => data_get($verification, 'verified_at'),
        ]]);
    }

    public function comments(Request $request, int $batchId): JsonResponse
    {
        $this->batch($batchId);
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            CscsWorkflowEvent::with('actor')
                ->where('batch_id', $batchId)
                ->whereNotNull('comment')
                ->latest('id')
                ->paginate($validated['per_page'] ?? 50)
        );
    }

    public function storeComment(Request $request, int $batchId): JsonResponse
    {
        $batch = $this->batch($batchId);
        $validated = $request->validate(['comment' => ['required', 'string', 'min:1', 'max:1000']]);
        $event = CscsWorkflowEvent::create([
            'batch_id' => $batch->id,
            'event_type' => 'COMMENT_ADDED',
            'from_status' => $batch->workflow_status,
            'to_status' => $batch->workflow_status,
            'actor_id' => $request->user()?->id,
            'comment' => $validated['comment'],
            'metadata' => ['kind' => 'review_comment'],
            'created_at' => now(),
        ])->load('actor');

        return response()->json(['message' => 'Comment posted', 'data' => $event], 201);
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
        $data = $this->service->respondToQuery($batchId, (int) $request->user()->id, $validated['comment']);
        $this->notify(['Reconciliation', 'Admin', 'Super Admin'], 'CSCS_QUERY_RESPONDED', 'CSCS query answered', $batchId, $request->user()->id);

        return $this->success('Query response recorded; reconcile and resubmit the batch', $data);
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
        $data = $this->service->reject($batchId, $request->user(), $validated['comment']);
        $this->notify([], 'CSCS_REJECTED', 'CSCS batch rejected', $batchId, $request->user()->id, [$this->batch($batchId)->uploaded_by]);

        return $this->success('CSCS batch rejected', $data);
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
        $this->notify(['Reconciliation', 'Internal Audit', 'Compliance', 'Admin', 'Super Admin'], 'CSCS_POSTING_QUEUED', 'CSCS posting queued', $batchId, $request->user()->id);

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

    public function snapshots(int $batchId): JsonResponse
    {
        $batch = $this->batch($batchId);

        return response()->json(['data' => $batch->snapshots()->latest('revision')->get()]);
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
        $batch = CscsUploadBatch::with(['register', 'events.actor', 'approvalActions.actor'])->findOrFail($batchId);
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(['rows', 'exceptions', 'reconciliation', 'preview', 'posting', 'audit', 'activity'])],
            'format' => ['nullable', Rule::in(['csv', 'pdf', 'xls', 'xlsx'])],
        ]);
        $type = $validated['type'] ?? 'rows';
        $format = $validated['format'] ?? 'csv';

        if ($format === 'pdf') {
            if (! in_array($type, ['audit', 'reconciliation'], true)) {
                throw ValidationException::withMessages(['format' => ['PDF is supported for audit and reconciliation reports.']]);
            }
            $summary = $type === 'reconciliation' ? ($batch->reconciliation ?? []) : ($batch->summary ?? []);
            $pdf = Pdf::loadView('exports.cscs-report', [
                'type' => $type,
                'title' => $type === 'audit' ? 'CSCS Audit Report' : 'CSCS Reconciliation Report',
                'batch' => $batch,
                'summary' => $summary,
                'verification' => data_get($batch->reconciliation, 'post_verification'),
                'events' => $batch->events->sortBy('id'),
                'approvals' => $batch->approvalActions->sortBy('id'),
            ])->setPaper('a4', 'portrait');

            return $pdf->download("cscs_{$batchId}_{$type}.pdf");
        }

        if (in_array($format, ['xls', 'xlsx'], true)) {
            if ($type !== 'activity') {
                throw ValidationException::withMessages(['format' => ['Excel format is supported for the activity report.']]);
            }
            $writer = $format === 'xls' ? ExcelWriter::XLS : ExcelWriter::XLSX;

            return Excel::download(
                new CscsActivityExport($batch->events->sortBy('id')->values()),
                "cscs_{$batchId}_activity.{$format}",
                $writer
            );
        }

        if ($type === 'audit') {
            throw ValidationException::withMessages(['format' => ['The audit report is available as PDF.']]);
        }
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
            } elseif ($type === 'activity') {
                fputcsv($out, ['event_id', 'date', 'event', 'from_status', 'to_status', 'actor', 'actor_email', 'comment']);
                foreach ($batch->events->sortBy('id') as $event) {
                    fputcsv($out, [$event->id, optional($event->created_at)->toIso8601String(), $event->event_type, $event->from_status, $event->to_status, $event->actor?->name ?? $event->actor?->full_name, $event->actor?->email, $event->comment]);
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
        $belongs = ShareClass::whereKey($validated['share_class_id'])->where('register_id', $validated['register_id'])->exists();
        if (! $belongs) {
            throw ValidationException::withMessages(['share_class_id' => ['The share class does not belong to the selected register.']]);
        }

        return $validated;
    }

    private function transactionPayload(string $number, $rows, ?Collection $accounts = null): array
    {
        $accounts ??= collect();
        $debit = '0.000000';
        $credit = '0.000000';
        foreach ($rows as $row) {
            if ($row->sign === '-') {
                $debit = bcadd($debit, (string) $row->volume, 6);
            } elseif ($row->sign === '+') {
                $credit = bcadd($credit, (string) $row->volume, 6);
            }
        }
        $isBalanced = $rows->count() === 2 && bccomp($credit, $debit, 6) === 0;
        $exceptionCodes = $rows->pluck('exception_code')->filter()->unique()->values();
        $flaggedStatuses = $rows->pluck('resolution_status')->filter()
            ->reject(fn (string $status) => in_array($status, ['READY', 'POSTED'], true))
            ->unique()->values();
        $flagReasons = $exceptionCodes->merge($flaggedStatuses)->unique()->values();
        if (! $isBalanced) {
            $flagReasons->prepend('UNBALANCED_TRANSACTION');
            $flagReasons = $flagReasons->unique()->values();
        }
        $statuses = $rows->pluck('resolution_status')->filter()->unique()->values();
        $resolutionCode = $statuses->count() === 1 ? (string) $statuses->first() : 'MIXED';
        $actionRequired = $statuses->contains(fn (string $status) => ! in_array($status, ['READY', 'POSTED', 'CONFIRMED_REPLAY', 'RULE_EXCLUDED'], true));
        $riskLevel = $this->transactionRiskLevel($isBalanced, $rows, $flagReasons);
        $debitRow = $rows->firstWhere('sign', '-');
        $creditRow = $rows->firstWhere('sign', '+');
        $quantity = bccomp($debit, $credit, 6) === 0
            ? $debit
            : (bccomp($debit, $credit, 6) > 0 ? $debit : $credit);

        return [
            'transaction_number' => $number,
            'trade_date' => optional($rows->first()->trade_date)->format('Y-m-d'),
            'security_code' => $rows->first()->sec_code,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'quantity' => $quantity,
            'quantity_mismatch' => bccomp($debit, $credit, 6) !== 0,
            'net_total' => bcsub($credit, $debit, 6),
            'leg_count' => $rows->count(),
            'is_balanced' => $isBalanced,
            'balance_status' => $isBalanced ? 'BALANCED' : 'UNBALANCED',
            'is_flagged' => $flagReasons->isNotEmpty(),
            'flag_reasons' => $flagReasons->all(),
            'risk' => [
                'level' => $riskLevel,
                'label' => ucfirst(strtolower($riskLevel)),
                'reasons' => $flagReasons->all(),
            ],
            'resolution' => [
                'status' => $resolutionCode,
                'label' => $this->humanizeCode($resolutionCode),
                'action_required' => $actionRequired,
            ],
            'debit_account' => $debitRow ? $this->accountPayloadForRow($debitRow, $accounts) : null,
            'credit_account' => $creditRow ? $this->accountPayloadForRow($creditRow, $accounts) : null,
            'status' => $statuses,
            'legs' => $rows,
        ];
    }

    private function accountDisplayMap(Collection $accountIds): Collection
    {
        $ids = $accountIds->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return ShareholderRegisterAccount::with('shareholder')->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function accountPayloadForRow(CscsUploadRow $row, Collection $accounts): array
    {
        $account = $row->proposed_sra_id ? $accounts->get((int) $row->proposed_sra_id) : null;
        $profile = data_get($row->extra_details, 'master_profile', []);

        return [
            'register_account_id' => $row->proposed_sra_id,
            'shareholder_id' => $account?->shareholder_id,
            'shareholder_name' => $account?->shareholder?->full_name ?? data_get($profile, 'full_name'),
            'shareholder_account_number' => $account?->shareholder?->account_no,
            'register_account_number' => $account?->shareholder_no,
            'chn' => $account?->chn ?? ($row->identifier_type === 'chn' ? $row->identifier_value : null),
            'cscs_account_number' => $account?->cscs_account_no ?? ($row->identifier_type === 'cscs_account_no' ? $row->identifier_value : null),
            'identifier_type' => $row->identifier_type,
            'identifier_value' => $row->identifier_value,
            'match_method' => $row->match_method,
            'current_quantity' => $row->proposed_before_qty,
            'proposed_quantity' => $row->proposed_after_qty,
            'is_new_account' => ! $row->proposed_sra_id,
            'proposed_profile' => ! $row->proposed_sra_id ? $profile : null,
        ];
    }

    private function transactionRiskLevel(bool $isBalanced, Collection $rows, Collection $flagReasons): string
    {
        $criticalCodes = [
            'INVALID_FORMAT', 'DUPLICATE_SOURCE_ROW', 'GROUP_STRUCTURAL_ERROR',
            'PARTIAL_GROUP_EXCLUSION', 'PARTIAL_REPLAY', 'INSUFFICIENT_HOLDING',
        ];
        if ($rows->pluck('exception_code')->filter()->intersect($criticalCodes)->isNotEmpty()) {
            return 'CRITICAL';
        }
        if (! $isBalanced || $rows->pluck('resolution_status')->intersect(['INVALID', 'UNRESOLVED'])->isNotEmpty()) {
            return 'HIGH';
        }

        return $flagReasons->isNotEmpty() ? 'MEDIUM' : 'LOW';
    }

    private function humanizeCode(?string $code): ?string
    {
        return $code ? ucwords(strtolower(str_replace('_', ' ', $code))) : null;
    }

    private function exceptionPayload(CscsUploadRow $row, Collection $accounts, Collection $history): array
    {
        $payload = $row->toArray();
        $payload['severity'] = $this->exceptionSeverity($row);
        $payload['is_blocking'] = $this->isBlockingException($row);
        $payload['exception_label'] = $this->humanizeCode($row->exception_code);
        $payload['parsed_record'] = [
            'transaction_number' => $row->tran_no,
            'sequence' => $row->tran_seq,
            'trade_date' => optional($row->trade_date)->format('Y-m-d'),
            'security_code' => $row->sec_code,
            'quantity' => $row->volume,
            'direction' => $row->sign === '-' ? 'DEBIT' : ($row->sign === '+' ? 'CREDIT' : null),
            'identifier_type' => $row->identifier_type,
            'identifier_value' => $row->identifier_value,
        ];
        $payload['matched_account'] = $row->proposed_sra_id ? $this->accountPayloadForRow($row, $accounts) : null;
        $payload['allowed_resolution_types'] = $this->allowedExceptionResolutions($row);
        $payload['suggested_resolution'] = $this->suggestedExceptionResolution($row);
        $payload['resolution_history'] = $history->values()->map(fn (CscsWorkflowEvent $event) => $event->toArray())->all();

        return $payload;
    }

    private function exceptionHistory(int $batchId): Collection
    {
        return CscsWorkflowEvent::with('actor')
            ->where('batch_id', $batchId)
            ->where('event_type', 'EXCEPTION_RESOLVED')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CscsWorkflowEvent $event) => (int) data_get($event->metadata, 'row_id'));
    }

    private function isBlockingException(CscsUploadRow $row): bool
    {
        return ! in_array($row->resolution_status, ['READY', 'POSTED', 'RULE_EXCLUDED', 'CONFIRMED_REPLAY'], true);
    }

    private function exceptionSeverity(CscsUploadRow $row): string
    {
        if (! $this->isBlockingException($row)) {
            return 'INFO';
        }
        $critical = [
            'INVALID_FORMAT', 'DUPLICATE_SOURCE_ROW', 'GROUP_STRUCTURAL_ERROR',
            'PARTIAL_GROUP_EXCLUSION', 'PARTIAL_REPLAY', 'INSUFFICIENT_HOLDING',
            'DEBIT_ACCOUNT_NOT_FOUND', 'AMBIGUOUS_MASTER_RECORD', 'AMBIGUOUS_PROFILE_MATCH',
        ];

        return in_array($row->exception_code, $critical, true) ? 'CRITICAL' : 'WARNING';
    }

    private function allowedExceptionResolutions(CscsUploadRow $row): array
    {
        if (! $this->isBlockingException($row)) {
            return [];
        }
        if (in_array($row->exception_code, ['DEBIT_ACCOUNT_NOT_FOUND', 'ACCOUNT_NOT_FOUND', 'AMBIGUOUS_PROFILE_MATCH', 'AMBIGUOUS_MASTER_RECORD'], true)) {
            return ['MAP_ACCOUNT', 'RULE_EXCLUDED'];
        }
        if (in_array($row->exception_code, ['REPLAY_DETECTED', 'DUPLICATE_MOVEMENT'], true)) {
            return ['CONFIRM_REPLAY', 'RULE_EXCLUDED'];
        }

        return ['MAP_ACCOUNT', 'RULE_EXCLUDED', 'CONFIRM_REPLAY'];
    }

    private function suggestedExceptionResolution(CscsUploadRow $row): ?array
    {
        if (! $this->isBlockingException($row)) {
            return null;
        }
        if ($row->exception_code === 'UNKNOWN_SECURITY') {
            return [
                'action' => 'CREATE_SECURITY_MAPPING',
                'label' => 'Create or correct the security mapping, then revalidate',
                'endpoint' => '/api/cscs/security-mappings',
            ];
        }
        if (in_array($row->exception_code, ['DEBIT_ACCOUNT_NOT_FOUND', 'ACCOUNT_NOT_FOUND', 'AMBIGUOUS_PROFILE_MATCH', 'AMBIGUOUS_MASTER_RECORD'], true)) {
            return [
                'action' => 'MAP_ACCOUNT',
                'label' => 'Map the record to an existing register account',
                'account_search_endpoint' => '/api/shareholders?register_id={registerId}&search={query}',
            ];
        }
        if (in_array($row->exception_code, ['REPLAY_DETECTED', 'DUPLICATE_MOVEMENT'], true)) {
            return ['action' => 'CONFIRM_REPLAY', 'label' => 'Confirm that this is an intentional replay'];
        }

        return ['action' => 'REVIEW', 'label' => 'Review the source record and choose an allowed resolution'];
    }

    private function exceptionCounts(Collection $rows): array
    {
        $blocking = $rows->filter(fn (CscsUploadRow $row) => $this->isBlockingException($row));

        return [
            'total' => $rows->count(),
            'blocking' => $blocking->filter(fn (CscsUploadRow $row) => $this->exceptionSeverity($row) === 'CRITICAL')->count(),
            'warnings' => $blocking->filter(fn (CscsUploadRow $row) => $this->exceptionSeverity($row) === 'WARNING')->count(),
            'resolved' => $rows->count() - $blocking->count(),
            'remaining' => $blocking->count(),
        ];
    }

    private function reviewSummary(CscsUploadBatch $batch, Collection $effects, Collection $securityMappings): array
    {
        $rows = CscsUploadRow::where('batch_id', $batch->id)->where('file_type', 'movement')->get();
        $exceptionRows = $rows->filter(fn (CscsUploadRow $row) => $row->exception_code
            || in_array($row->resolution_status, ['RULE_EXCLUDED', 'CONFIRMED_REPLAY'], true)
            || $row->resolved_at);
        $exceptionCounts = $this->exceptionCounts($exceptionRows->values());
        $requiredSecurityCodes = $rows->pluck('sec_code')->filter()->unique()->values();
        $verifiedMappings = $securityMappings->filter(fn (CscsSecurityMapping $mapping) => $mapping->is_active
            && (int) $mapping->register_id === (int) $batch->register_id
            && $requiredSecurityCodes->contains($mapping->security_code));

        return [
            'total_debit' => data_get($batch->reconciliation, 'total_debit', '0.000000'),
            'total_credit' => data_get($batch->reconciliation, 'total_credit', '0.000000'),
            'net_movement' => data_get($batch->reconciliation, 'net_movement', '0.000000'),
            'affected_accounts' => $effects->count(),
            'proposed_new_accounts' => $effects->where('is_new_account', true)->count(),
            'manual_resolutions' => $rows->where('match_method', 'manual_mapping')->count(),
            'security_mappings' => [
                'required' => $requiredSecurityCodes->count(),
                'verified' => $verifiedMappings->count(),
                'unmapped' => max(0, $requiredSecurityCodes->count() - $verifiedMappings->count()),
            ],
            'exceptions' => $exceptionCounts + [
                'replay_transactions' => $rows->where('resolution_status', 'CONFIRMED_REPLAY')->pluck('tran_no')->filter()->unique()->count(),
            ],
            'checks' => [
                'security_mappings_complete' => $requiredSecurityCodes->count() === $verifiedMappings->count(),
                'no_blocking_exceptions' => $exceptionCounts['remaining'] === 0,
                'snapshot_available' => filled($batch->snapshot_hash),
            ],
        ];
    }

    private function batchReviewRelations(): array
    {
        return [
            'approvalActions.actor', 'events.actor', 'register.company',
            'uploader', 'reconciler', 'submitter', 'approver', 'rejector', 'poster',
        ];
    }

    private function verificationComparison(string $metric, string $label, mixed $approved, mixed $actual, string $matchedStatus = 'MATCHED'): array
    {
        $decimal = (is_string($approved) && str_contains($approved, '.'))
            || (is_string($actual) && str_contains($actual, '.'));
        $variance = $decimal
            ? bcsub((string) $actual, (string) $approved, 6)
            : (int) $actual - (int) $approved;
        $matched = $decimal
            ? bccomp((string) $actual, (string) $approved, 6) === 0
            : (int) $actual === (int) $approved;

        return [
            'metric' => $metric,
            'label' => $label,
            'approved' => $approved,
            'actual' => $actual,
            'variance' => $variance,
            'status' => $matched ? $matchedStatus : 'VARIANCE',
            'matched' => $matched,
        ];
    }

    private function transactionGroupsQuery(int $batchId): Builder
    {
        $balanced = $this->balancedTransactionSql();
        $flagged = $this->flaggedTransactionSql();

        return CscsUploadRow::query()
            ->where('batch_id', $batchId)
            ->where('file_type', 'movement')
            ->whereNotNull('tran_no')
            ->select('tran_no')
            ->selectRaw('COUNT(*) as leg_count')
            ->selectRaw("CASE WHEN {$balanced} THEN 1 ELSE 0 END as is_balanced")
            ->selectRaw("CASE WHEN {$flagged} THEN 1 ELSE 0 END as is_flagged")
            ->groupBy('tran_no');
    }

    private function applyTransactionFilters(Builder $groups, int $batchId, array $filters): void
    {
        foreach (['search', 'resolution_status', 'security_code', 'trade_date_from', 'trade_date_to'] as $filter) {
            if (! isset($filters[$filter]) || $filters[$filter] === '') {
                continue;
            }

            $matchingTransactions = CscsUploadRow::query()
                ->select('tran_no')
                ->where('batch_id', $batchId)
                ->where('file_type', 'movement')
                ->whereNotNull('tran_no');

            match ($filter) {
                'search' => $matchingTransactions->where(fn (Builder $query) => $query
                    ->where('tran_no', 'like', '%'.$filters[$filter].'%')
                    ->orWhere('identifier_value', 'like', '%'.$filters[$filter].'%')
                    ->orWhere('sec_code', 'like', '%'.$filters[$filter].'%')),
                'resolution_status' => $matchingTransactions->where('resolution_status', $filters[$filter]),
                'security_code' => $matchingTransactions->where('sec_code', $filters[$filter]),
                'trade_date_from' => $matchingTransactions->whereDate('trade_date', '>=', $filters[$filter]),
                'trade_date_to' => $matchingTransactions->whereDate('trade_date', '<=', $filters[$filter]),
            };

            $groups->whereIn('tran_no', $matchingTransactions->distinct());
        }

        if (isset($filters['balance_status'])) {
            $groups->havingRaw(
                $filters['balance_status'] === 'BALANCED'
                    ? $this->balancedTransactionSql()
                    : 'NOT ('.$this->balancedTransactionSql().')'
            );
        }
        if (array_key_exists('is_flagged', $filters) && $filters['is_flagged'] !== null) {
            $groups->havingRaw(
                $filters['is_flagged']
                    ? $this->flaggedTransactionSql()
                    : 'NOT ('.$this->flaggedTransactionSql().')'
            );
        }
    }

    private function balancedTransactionSql(): string
    {
        return "COUNT(*) = 2 AND COALESCE(SUM(CASE WHEN sign = '-' THEN volume ELSE 0 END), 0) = COALESCE(SUM(CASE WHEN sign = '+' THEN volume ELSE 0 END), 0)";
    }

    private function flaggedTransactionSql(): string
    {
        return 'NOT ('.$this->balancedTransactionSql().") OR MAX(CASE WHEN exception_code IS NOT NULL OR resolution_status NOT IN ('READY', 'POSTED') THEN 1 ELSE 0 END) = 1";
    }

    private function batchPayload(CscsUploadBatch $batch, Request $request): array
    {
        $user = $request->user();
        $status = $batch->workflow_status;
        $isMaker = $user && (int) $batch->uploaded_by === (int) $user->id;
        $actions = ['view'];
        if ($isMaker && $user->can('cscs.reconcile') && in_array($status, ['DRAFT_REVIEW', 'QUERY_RAISED', 'STALE'], true)) {
            $actions = array_merge($actions, ['resolve', 'revalidate']);
        }
        if ($isMaker && $user->can('cscs.submit') && in_array($status, ['PROCESSING', 'DRAFT_REVIEW', 'RECONCILED', 'QUERY_RAISED', 'STALE', 'PROCESSING_FAILED'], true)) {
            $actions[] = 'cancel';
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

    private function paginatedWithPrecision(LengthAwarePaginator $paginator, int $batchId, array $meta = []): JsonResponse
    {
        $payload = $paginator->toArray();
        $payload['meta'] = array_merge($this->precisionMeta($batchId), $meta);

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
