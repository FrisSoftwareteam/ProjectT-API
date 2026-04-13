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
            'representative_type' => ['required', 'in:executor,administrator'],
            'full_name' => ['required', 'string', 'max:255'],
            'id_type' => ['nullable', 'string', 'max:50'],
            'id_value' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
