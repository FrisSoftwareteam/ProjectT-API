<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareholderChangeRequestDecisionRequest extends FormRequest
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
        $isReject = str_ends_with($this->path(), '/reject');

        return [
            'remarks' => ($isReject ? 'required' : 'nullable').'|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'remarks.required' => 'A reason is required when rejecting a pending update.',
        ];
    }
}
