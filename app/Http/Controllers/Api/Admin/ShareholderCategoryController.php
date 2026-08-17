<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareholderCategoryRequest;
use App\Models\ShareholderCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareholderCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ShareholderCategory::query()->withCount('registerAccounts');

        if ($request->boolean('include_deleted')) {
            $query->withTrashed();
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->filled('default_holder_type')) {
            $query->where('default_holder_type', $request->string('default_holder_type'));
        }
        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->input('search')).'%';
            $query->where(fn ($builder) => $builder
                ->where('code', 'like', $search)
                ->orWhere('name', 'like', $search));
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('code')->paginate($request->integer('per_page', 25)),
        ]);
    }

    public function store(ShareholderCategoryRequest $request): JsonResponse
    {
        $category = ShareholderCategory::query()->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Shareholder category created successfully',
            'data' => $category,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $shareholderCategory = ShareholderCategory::query()->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $shareholderCategory->loadCount('registerAccounts'),
        ]);
    }

    public function update(
        ShareholderCategoryRequest $request,
        int $id
    ): JsonResponse {
        $shareholderCategory = ShareholderCategory::query()->findOrFail($id);
        $payload = $request->validated();
        if (isset($payload['code'])
            && $payload['code'] !== $shareholderCategory->code
            && $shareholderCategory->registerAccounts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A category code cannot be changed after it has been assigned.',
                'errors' => ['code' => ['Category code is immutable while the category is in use.']],
            ], 422);
        }

        if (! empty($payload['default_holder_type'])) {
            $hasConflictingAccounts = $shareholderCategory->registerAccounts()
                ->whereHas('shareholder', fn ($query) => $query
                    ->where('holder_type', '!=', $payload['default_holder_type']))
                ->exists();
            if ($hasConflictingAccounts) {
                return response()->json([
                    'success' => false,
                    'message' => 'The default holder type conflicts with existing category assignments.',
                    'errors' => [
                        'default_holder_type' => ['Update the conflicting register accounts before changing this default.'],
                    ],
                ], 422);
            }
        }

        $shareholderCategory->update($payload);

        return response()->json([
            'success' => true,
            'message' => 'Shareholder category updated successfully',
            'data' => $shareholderCategory->fresh()->loadCount('registerAccounts'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $shareholderCategory = ShareholderCategory::query()->findOrFail($id);

        if ($shareholderCategory->registerAccounts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This shareholder category is in use and cannot be archived.',
            ], 409);
        }

        $shareholderCategory->delete();

        return response()->json(null, 204);
    }

    public function restore(int $id): JsonResponse
    {
        $category = ShareholderCategory::withTrashed()->findOrFail($id);
        $category->restore();

        return response()->json([
            'success' => true,
            'message' => 'Shareholder category restored successfully',
            'data' => $category->fresh(),
        ]);
    }
}
