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
            'case_type' => ['required', 'in:probate,letters_of_administration'],
            'court_ref' => ['required', 'string', 'max:100'],
            'grant_date' => ['nullable', 'date'],
            'document_ref' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
            'status' => ['nullable', 'in:pending,granted,distributed,closed'],
            'opened_at' => ['nullable', 'date'],
            'closed_at' => ['nullable', 'date'],
        ];
    }
}
