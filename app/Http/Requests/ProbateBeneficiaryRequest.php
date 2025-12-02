<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProbateBeneficiaryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'beneficiary_shareholder_id' => ['nullable', 'exists:shareholders,id'],
            'beneficiary_name' => ['required_without:beneficiary_shareholder_id', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:100'],
            'share_class_id' => ['required', 'exists:share_classes,id'],
            'sra_id' => ['nullable', 'exists:shareholder_register_accounts,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
