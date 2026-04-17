<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EstateDistributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_shareholder_id' => ['required', 'exists:shareholders,id'],
            'share_class_id' => ['required', 'exists:share_classes,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'document_ref' => ['nullable', 'string', 'max:255'],
            'corporate_action_id' => ['nullable', 'exists:corporate_actions,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
