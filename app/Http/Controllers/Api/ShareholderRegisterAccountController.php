<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateShareholderRegisterAccountCategoryRequest;
use App\Models\ShareholderCategory;
use App\Models\ShareholderRegisterAccount;
use Illuminate\Http\JsonResponse;

class ShareholderRegisterAccountController extends Controller
{
    public function updateCategory(
        UpdateShareholderRegisterAccountCategoryRequest $request,
        int $id
    ): JsonResponse {
        $shareholderRegisterAccount = ShareholderRegisterAccount::query()->findOrFail($id);
        $categoryId = $request->validated('shareholder_category_id');

        if ($categoryId !== null) {
            $category = ShareholderCategory::query()->findOrFail($categoryId);
            $holderType = $shareholderRegisterAccount->shareholder()->value('holder_type');
            if (! $category->isCompatibleWith($holderType)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The shareholder category is incompatible with the shareholder holder type.',
                    'errors' => [
                        'shareholder_category_id' => [
                            "Category {$category->code} requires holder type {$category->default_holder_type}.",
                        ],
                    ],
                ], 422);
            }
        }

        $shareholderRegisterAccount->update(['shareholder_category_id' => $categoryId]);

        return response()->json([
            'success' => true,
            'message' => 'Shareholder category updated successfully',
            'data' => $shareholderRegisterAccount->fresh()->load('category'),
        ]);
    }
}
