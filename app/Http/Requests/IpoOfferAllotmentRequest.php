<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IpoOfferAllotmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shareholder_id' => ['required', 'exists:shareholders,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}

