<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CscsUploadRequest;
use App\Models\CscsUploadBatch;
use App\Models\CscsUploadRow;
use App\Services\CscsImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CscsUploadController extends Controller
{
    public function __construct(
        private readonly CscsImportService $importService
    ) {
    }

    public function import(CscsUploadRequest $request): JsonResponse
    {
        try {
            $files = $request->uploadedFiles();
            $registerId = $request->input('register_id');
            $result = $this->importService->import(
                $files,
                $registerId ? (int) $registerId : null,
                $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'CSCS upload processed',
                'data' => $result,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('CSCS import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'CSCS import failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = CscsUploadBatch::query();
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('register_id')) {
            $query->where('register_id', $request->query('register_id'));
        }

        $data = $query->withCount('rows')
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 15));

        return response()->json($data);
    }

    public function show(int $batchId): JsonResponse
    {
        $batch = CscsUploadBatch::withCount('rows')->findOrFail($batchId);
        $stats = CscsUploadRow::where('batch_id', $batchId)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'batch' => $batch,
            'status_counts' => $stats,
        ]);
    }

    public function rows(Request $request, int $batchId): JsonResponse
    {
        $query = CscsUploadRow::where('batch_id', $batchId)->orderBy('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('identifier')) {
            $query->where('identifier_value', 'like', '%' . $request->query('identifier') . '%');
        }
        if ($request->filled('tran_no')) {
            $query->where('tran_no', $request->query('tran_no'));
        }

        return response()->json($query->paginate((int) $request->query('per_page', 50)));
    }

    public function exceptions(Request $request, int $batchId): JsonResponse
    {
        $query = CscsUploadRow::where('batch_id', $batchId)
            ->whereIn('status', ['failed', 'skipped'])
            ->orderBy('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $data = $query->paginate((int) $request->query('per_page', 50), [
            'id',
            'source_filename',
            'row_number',
            'status',
            'tran_no',
            'tran_seq',
            'identifier_value',
            'error_message',
            'created_at',
        ]);

        return response()->json($data);
    }

    public function reprocessFailed(int $batchId): JsonResponse
    {
        try {
            $result = $this->importService->reprocessFailedRows($batchId, auth()->id());
            return response()->json([
                'success' => true,
                'message' => 'Failed rows reprocessed',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('CSCS reprocess failed', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Reprocess failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function export(int $batchId)
    {
        $batch = CscsUploadBatch::findOrFail($batchId);
        $rows = CscsUploadRow::where('batch_id', $batchId)->orderBy('id')->cursor();

        $filename = 'cscs_upload_' . $batchId . '_rows.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'batch_id',
                'file_type',
                'source_filename',
                'row_number',
                'tran_no',
                'tran_seq',
                'trade_date',
                'sec_code',
                'identifier_type',
                'identifier_value',
                'sign',
                'volume',
                'status',
                'matched_by',
                'error_message',
                'before_qty',
                'delta_qty',
                'after_qty',
                'shareholder_id',
                'sra_id',
                'share_class_id',
                'share_transaction_id',
                'fingerprint',
                'created_at',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id,
                    $row->batch_id,
                    $row->file_type,
                    $row->source_filename,
                    $row->row_number,
                    $row->tran_no,
                    $row->tran_seq,
                    optional($row->trade_date)->format('Y-m-d'),
                    $row->sec_code,
                    $row->identifier_type,
                    $row->identifier_value,
                    $row->sign,
                    $row->volume,
                    $row->status,
                    $row->matched_by,
                    $row->error_message,
                    $row->before_qty,
                    $row->delta_qty,
                    $row->after_qty,
                    $row->shareholder_id,
                    $row->sra_id,
                    $row->share_class_id,
                    $row->share_transaction_id,
                    $row->fingerprint,
                    $row->created_at,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }
}
