<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareholderCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $categoryId = $this->route('id');

        return [
            'code' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:10',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('shareholder_categories', 'code')->ignore($categoryId),
            ],
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:150'],
            'default_holder_type' => ['nullable', Rule::in(['individual', 'corporate'])],
            'requires_joint_holders' => ['sometimes', 'boolean'],
            'requires_review' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'source_system' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
