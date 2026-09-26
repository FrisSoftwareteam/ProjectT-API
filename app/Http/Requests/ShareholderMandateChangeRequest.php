<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareholderMandateChangeRequest extends FormRequest
{
    /**
     * The bank mandate fields eligible for a pending update.
     */
    public const ELIGIBLE_FIELDS = [
        'bank_name',
        'account_name',
        'account_number',
        'bvn',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_name' => 'required|string|max:150',
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'bvn' => 'nullable|string|max:20',
            'reason' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'bank_name.required' => 'Bank name is required.',
            'account_name.required' => 'Account name is required.',
            'account_number.required' => 'Account number is required.',
        ];
    }

    /**
     * Only the eligible mandate fields that were actually submitted.
     */
    public function proposedFields(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::ELIGIBLE_FIELDS));
    }
}
