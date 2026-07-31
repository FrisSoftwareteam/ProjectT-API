<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegacyMigrationBatch;
use App\Models\LegacyMigrationRecord;
use App\Services\LegacyMigration\LegacyMigrationPackageRegistry;
use App\Services\LegacyMigration\LegacyMigrationWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyMigrationController extends Controller
{
    public function __construct(
        private readonly LegacyMigrationWorkflowService $workflow,
        private readonly LegacyMigrationPackageRegistry $packages
    ) {}

    public function packages(): JsonResponse
    {
        return response()->json(['data' => $this->packages->all()]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'register_id' => ['nullable', 'integer', 'exists:registers,id'],
            'package_key' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = LegacyMigrationBatch::query()->with(['register.company', 'shareClass'])->latest('id');
        $query->when($validated['status'] ?? null, fn ($q, $value) => $q->where('status', $value));
        $query->when($validated['register_id'] ?? null, fn ($q, $value) => $q->where('register_id', $value));
        $query->when($validated['package_key'] ?? null, fn ($q, $value) => $q->where('package_key', $value));

        return response()->json($query->paginate($validated['per_page'] ?? 20));
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_key' => ['required', 'string', 'max:100'],
            'register_id' => ['required', 'integer', 'exists:registers,id'],
            'share_class_id' => ['required', 'integer', 'exists:share_classes,id'],
        ]);
        $batch = $this->workflow->create($validated['package_key'], $validated['register_id'], $validated['share_class_id'], (int) $request->user()->id);

        return response()->json(['message' => 'Migration batch created without publishing data.', 'data' => $batch], 201);
    }

    public function show(int $batchId): JsonResponse
    {
        $batch = LegacyMigrationBatch::with(['register.company', 'shareClass', 'approvals.actor', 'events.actor'])->findOrFail($batchId);
        $counts = LegacyMigrationRecord::where('batch_id', $batchId)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return response()->json(['data' => ['batch' => $batch, 'record_status_counts' => $counts]]);
    }

    public function stage(Request $request, int $batchId): JsonResponse
    {
        $batch = $this->workflow->dispatchStaging($batchId, (int) $request->user()->id);

        return response()->json(['message' => 'Staging was queued.', 'data' => $batch], 202);
    }

    public function reconcile(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['message' => 'Reconciliation completed.', 'data' => $this->workflow->reconcile($batchId, (int) $request->user()->id, $validated['comment'] ?? null)]);
    }

    public function submit(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['message' => 'Migration submitted for independent approval.', 'data' => $this->workflow->submit($batchId, (int) $request->user()->id, $validated['comment'] ?? null)]);
    }

    public function approve(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['message' => 'Migration snapshot approved.', 'data' => $this->workflow->approve($batchId, (int) $request->user()->id, $validated['comment'])]);
    }

    public function publish(Request $request, int $batchId): JsonResponse
    {
        $batch = $this->workflow->dispatchPublishing($batchId, (int) $request->user()->id);

        return response()->json(['message' => 'Approved migration was queued for publishing.', 'data' => $batch], 202);
    }

    public function rollback(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:20', 'max:1000']]);
        $batch = $this->workflow->dispatchRollback($batchId, (int) $request->user()->id, $validated['comment']);

        return response()->json(['message' => 'Controlled rollback was queued.', 'data' => $batch], 202);
    }

    public function cancel(Request $request, int $batchId): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'min:10', 'max:1000']]);

        return response()->json(['message' => 'Unpublished migration cancelled.', 'data' => $this->workflow->cancel($batchId, (int) $request->user()->id, $validated['comment'])]);
    }

    public function events(int $batchId): JsonResponse
    {
        $batch = LegacyMigrationBatch::findOrFail($batchId);

        return response()->json($batch->events()->with('actor')->orderBy('id')->paginate(100));
    }
}
