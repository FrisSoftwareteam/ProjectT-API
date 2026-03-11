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
            'files' => ['required', 'array', 'min:1', 'max:2'],
            'files.*' => ['required', 'file', 'mimes:txt,csv'],
            'register_id' => ['nullable', 'exists:registers,id'],
        ];
    }
}
