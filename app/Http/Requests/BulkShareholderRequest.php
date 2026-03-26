<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkShareholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shareholders' => ['required', 'array', 'min:1'],
            'shareholders.*.holder_type' => ['required', 'in:individual,corporate'],
            'shareholders.*.first_name' => ['required', 'string', 'max:255'],
            'shareholders.*.last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shareholders.*.middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shareholders.*.email' => ['required', 'email', 'distinct', 'unique:shareholders,email'],
            'shareholders.*.phone' => ['required', 'string', 'max:32', 'distinct', 'unique:shareholders,phone'],
            'shareholders.*.date_of_birth' => ['sometimes', 'nullable', 'date'],
            'shareholders.*.sex' => ['sometimes', 'nullable', 'in:male,female,other'],
            'shareholders.*.rc_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'shareholders.*.nin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shareholders.*.bvn' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shareholders.*.tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'shareholders.*.next_of_kin_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shareholders.*.next_of_kin_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'shareholders.*.next_of_kin_relationship' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shareholders.*.status' => ['required', 'in:active,dormant,deceased,closed'],
        ];
    }
}
