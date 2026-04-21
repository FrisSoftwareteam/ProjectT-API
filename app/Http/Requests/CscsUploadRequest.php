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
        $this->files->set('files', $this->normalizedFiles());
    }

    public function validationData(): array
    {
        return array_replace($this->all(), [
            'files' => $this->normalizedFiles(),
        ]);
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:2'],
            'files.*' => ['required', 'file', 'mimes:txt,csv'],
            'register_id' => ['nullable', 'exists:registers,id'],
        ];
    }

    private function normalizedFiles(): array
    {
        return $this->flattenUploadedFiles($this->allFiles()['files'] ?? $this->file('files'));
    }

    private function flattenUploadedFiles(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        $flattened = [];

        foreach ($files as $file) {
            array_push($flattened, ...$this->flattenUploadedFiles($file));
        }

        return $flattened;
    }
}
