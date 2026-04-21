<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

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
            'files' => $this->hasUploadedFiles() ? true : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'files' => ['required'],
            'register_id' => ['nullable', 'exists:registers,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $files = $this->uploadedFiles();

            if (count($files) < 1) {
                $validator->errors()->add('files', 'At least one CSCS file is required.');

                return;
            }

            if (count($files) > 2) {
                $validator->errors()->add('files', 'The files field must not have more than 2 items.');

                return;
            }

            foreach ($files as $index => $file) {
                if (! $file->isValid()) {
                    $validator->errors()->add("files.$index", 'The uploaded file is invalid.');
                    continue;
                }

                if (! in_array(strtolower($file->getClientOriginalExtension()), ['txt', 'csv'], true)) {
                    $validator->errors()->add("files.$index", 'Each CSCS file must be a txt or csv file.');
                }
            }
        });
    }

    private function normalizedFiles(): array
    {
        return $this->flattenUploadedFiles($this->allFiles());
    }

    public function uploadedFiles(): array
    {
        return $this->normalizedFiles();
    }

    private function hasUploadedFiles(): bool
    {
        return count($this->uploadedFiles()) > 0;
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
