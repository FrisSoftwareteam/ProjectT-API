<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SraGuardianRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'sra_id' => ['required', 'exists:shareholder_register_accounts,id'],
            'guardian_shareholder_id' => ['nullable', 'exists:shareholders,id'],
            'guardian_name' => ['required_without:guardian_shareholder_id', 'string', 'max:255'],
            'guardian_contact' => ['nullable', 'string', 'max:255'],
            'document_ref' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'permissions' => ['nullable', 'array'],
            'verified_status' => ['nullable', 'in:pending,verified,rejected'],
            'verified_by' => ['nullable', 'exists:admin_users,id'],
            'verified_at' => ['nullable', 'date'],
        ];
    }
}
