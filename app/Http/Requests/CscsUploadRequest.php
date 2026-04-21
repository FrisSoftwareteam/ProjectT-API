<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class CscsUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $files = $this->file('files');

        if ($files instanceof UploadedFile) {
            $this->files->set('files', [$files]);

            return;
        }

        if (is_array($files)) {
            $this->files->set('files', array_values(array_filter($files)));
        }
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
