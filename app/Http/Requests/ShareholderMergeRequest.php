<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareholderMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'primary_shareholder_id' => ['required', 'exists:shareholders,id', 'different:duplicate_shareholder_id'],
            'duplicate_shareholder_id' => ['required', 'exists:shareholders,id'],
            'verification_basis' => ['required', 'in:chn,identity'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

