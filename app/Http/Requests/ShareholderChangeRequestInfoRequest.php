<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareholderChangeRequestInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required|in:banker_confirmation,shareholder_request',
            'note' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'The type of information being requested is required.',
            'type.in' => 'Type must be either banker_confirmation or shareholder_request.',
        ];
    }
}
