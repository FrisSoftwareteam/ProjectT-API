<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EstateCaseRepresentativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shareholder_id' => ['nullable', 'required_without:shareholder_ids', 'exists:shareholders,id'],
            'shareholder_ids' => ['nullable', 'required_without:shareholder_id', 'array', 'min:1'],
            'shareholder_ids.*' => ['required', 'integer', 'distinct', 'exists:shareholders,id'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
