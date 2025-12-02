<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserActivityLogRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => ['nullable', 'exists:admin_users,id'],
            'action' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->has('metadata') && is_string($this->input('metadata'))) {
            $decoded = json_decode($this->input('metadata'), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['metadata' => $decoded]);
            }
        }
    }
}
