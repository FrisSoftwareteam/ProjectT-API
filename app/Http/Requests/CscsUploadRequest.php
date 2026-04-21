<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CscsUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'register_id' => ['nullable', 'exists:registers,id'],
        ];
    }
}
