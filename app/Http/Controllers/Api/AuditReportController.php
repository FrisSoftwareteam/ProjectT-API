<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuditReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:admin_users,id'],
            'shareholder_id' => ['nullable', 'integer'],
            'shareholder_reference' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'activity_category' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = UserActivityLog::query()
            ->with(['user.roles'])
            ->latest('created_at');

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['role'])) {
            $role = $validated['role'];
            $query->whereHas('user.roles', fn ($roles) => $roles->where('name', $role));
        }

        if (! empty($validated['shareholder_id'])) {
            $this->whereMetadataContainsAny($query, [
                '"shareholder_id":'.$validated['shareholder_id'],
                '"shareholder_id": '.$validated['shareholder_id'],
                '"shareholder":"'.$validated['shareholder_id'].'"',
                '"shareholder": "'.$validated['shareholder_id'].'"',
                '"shareholder":'.$validated['shareholder_id'],
                '"shareholder": '.$validated['shareholder_id'],
            ]);
        }

        if (! empty($validated['shareholder_reference'])) {
            $this->whereMetadataContainsAny($query, [$validated['shareholder_reference']]);
        }

        if (! empty($validated['activity_category'])) {
            $category = $this->normalizeCategory($validated['activity_category']);
            $query->where(function ($builder) use ($category) {
                $builder->where('action', 'like', "%{$category}%")
                    ->orWhere('metadata', 'like', "%{$category}%");
            });
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['q'])) {
            $q = $validated['q'];
            $query->where(function ($builder) use ($q) {
                $builder->where('action', 'like', "%{$q}%")
                    ->orWhere('metadata', 'like', "%{$q}%")
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery->where('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        $report = $query
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (UserActivityLog $log) => $this->formatLog($log));

        return response()->json([
            'success' => true,
            'message' => 'Audit report retrieved successfully',
            'data' => $report,
        ]);
    }

    private function whereMetadataContainsAny($query, array $needles): void
    {
        $query->where(function ($builder) use ($needles) {
            foreach ($needles as $needle) {
                $builder->orWhere('metadata', 'like', "%{$needle}%");
            }
        });
    }

    private function formatLog(UserActivityLog $log): array
    {
        $metadata = $log->metadata ?? [];
        $user = $log->user;
        $roles = $user?->roles?->pluck('name')->values()->all() ?? [];

        return [
            'id' => $log->id,
            'user' => $user ? [
                'id' => $user->id,
                'name' => trim("{$user->first_name} {$user->last_name}"),
                'email' => $user->email,
            ] : null,
            'roles' => $roles,
            'activity_type' => $log->action,
            'activity_category' => $this->categoryFromAction($log->action),
            'affected_record' => $this->affectedRecord($metadata),
            'shareholder_reference' => $this->shareholderReference($metadata),
            'metadata' => $metadata,
            'date' => $log->created_at?->toDateString(),
            'time' => $log->created_at?->format('H:i:s'),
            'created_at' => $log->created_at,
        ];
    }

    private function affectedRecord(array $metadata): ?array
    {
        $routeParameters = Arr::get($metadata, 'route_parameters', []);
        if (is_array($routeParameters) && $routeParameters !== []) {
            return [
                'type' => Arr::get($metadata, 'route'),
                'reference' => Arr::get($metadata, 'path'),
                'identifiers' => $routeParameters,
            ];
        }

        foreach (['entity_type', 'entity_id', 'reference'] as $key) {
            if (array_key_exists($key, $metadata)) {
                return [
                    'type' => $metadata['entity_type'] ?? null,
                    'reference' => $metadata['reference'] ?? $metadata['entity_id'] ?? null,
                    'identifiers' => array_filter([
                        'entity_id' => $metadata['entity_id'] ?? null,
                    ]),
                ];
            }
        }

        return null;
    }

    private function shareholderReference(array $metadata): mixed
    {
        foreach (['shareholder_reference', 'shareholder_no', 'account_no', 'shareholder_id'] as $key) {
            $value = $this->findInMetadata($metadata, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function findInMetadata(array $metadata, string $key): mixed
    {
        if (array_key_exists($key, $metadata)) {
            return $metadata[$key];
        }

        foreach ($metadata as $value) {
            if (is_array($value)) {
                $found = $this->findInMetadata($value, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function categoryFromAction(string $action): string
    {
        $category = preg_replace('/_(created|updated|deleted)$/', '', $action) ?? $action;
        $category = preg_replace('/^(api|admin)_/', '', $category) ?? $category;

        return $category;
    }

    private function normalizeCategory(string $category): string
    {
        return str_replace('-', '_', strtolower(trim($category)));
    }
}
