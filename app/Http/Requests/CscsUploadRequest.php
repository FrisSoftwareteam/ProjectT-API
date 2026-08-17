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
            'register_id' => ['required', 'integer', 'exists:registers,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'business_reference' => ['nullable', 'string', 'max:100'],
            'files' => ['required', 'array', 'min:1', 'max:2'],
            'files.*' => ['required', 'file', 'mimes:txt,csv', 'max:'.config('cscs.max_upload_kb', 20480)],
        ];
    }
}
