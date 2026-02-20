<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareholderMandateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH'], true);

        return [
            'shareholder_id' => $isUpdate ? 'sometimes|exists:shareholders,id' : 'required|exists:shareholders,id',
            'bank_name' => 'required|string|max:150',
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'bvn' => 'nullable|string|max:20',
            'status' => 'required|in:pending,verified,active,rejected,revoked',
            'verified_by' => 'nullable|exists:admin_users,id',
            'verified_at' => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'shareholder_id.required' => 'The shareholder ID is required.',
            'shareholder_id.exists' => 'The shareholder ID is invalid.',
        ];
    }
}
