<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IpoOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'register_id' => ['nullable', 'exists:registers,id', 'required_without:new_register_name'],
            'new_register_name' => ['nullable', 'string', 'max:255', 'required_without:register_id'],
            'instrument_type' => ['nullable', 'string', 'max:100'],
            'capital_behaviour_type' => ['nullable', 'in:constant,open_ended,amortising'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'class_code' => ['required', 'string', 'max:32'],
            'approved_units' => ['required', 'numeric', 'gt:0'],
        ];
    }
}

