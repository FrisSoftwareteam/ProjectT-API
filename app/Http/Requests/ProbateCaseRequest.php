<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProbateCaseRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'shareholder_id' => ['required', 'exists:shareholders,id'],
            'court_ref' => ['required', 'string', 'max:100'],
            'executor_name' => ['required', 'string', 'max:255'],
            'document_ref' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,granted,distributed,closed'],
            'opened_at' => ['nullable', 'date'],
            'closed_at' => ['nullable', 'date'],
        ];
    }
}
