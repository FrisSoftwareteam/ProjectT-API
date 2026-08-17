<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateShareholderRegisterAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'register_id' => 'required|exists:registers,id',
            'shareholder_category_id' => [
                'nullable',
                'integer',
                Rule::exists('shareholder_categories', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'shareholder_no' => 'nullable|string|max:30',
            'chn' => 'nullable|string|max:50',
            'cscs_account_no' => 'nullable|string|max:50',
            'residency_status' => 'nullable|in:resident,non_resident',
            'kyc_level' => 'nullable|in:basic,standard,enhanced',
            'status' => 'nullable|in:active,suspended,closed',
        ];
    }
}
