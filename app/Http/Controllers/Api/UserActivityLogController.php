<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserActivityLogRequest;
use App\Models\UserActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = UserActivityLog::with('user');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->query('action').'%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('action', 'like', "%{$q}%")
                    ->orWhere('metadata', 'like', "%{$q}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $data = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($data);
    }

    public function show(UserActivityLog $userActivityLog): JsonResponse
    {
        $userActivityLog->load('user');
        return response()->json($userActivityLog);
    }

    public function store(UserActivityLogRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $log = UserActivityLog::create($payload);
        return response()->json($log, 201);
    }

    public function update(UserActivityLogRequest $request, UserActivityLog $userActivityLog): JsonResponse
    {
        $userActivityLog->update($request->validated());
        return response()->json($userActivityLog);
    }

    public function destroy(UserActivityLog $userActivityLog)
    {
        $userActivityLog->delete();
        return response()->noContent();
    }

    public function bulkDestroy(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['message' => 'No ids provided'], 422);
        }
        UserActivityLog::whereIn('id', $ids)->delete();
        return response()->noContent();
    }

    /**
     * Restore a soft-deleted log.
     */
    public function restore($id)
    {
        $log = UserActivityLog::withTrashed()->findOrFail($id);
        $log->restore();
        return response()->json($log);
    }

    /**
     * Permanently delete a log.
     */
    public function forceDelete($id)
    {
        $log = UserActivityLog::withTrashed()->findOrFail($id);
        $log->forceDelete();
        return response()->noContent();
    }
}
