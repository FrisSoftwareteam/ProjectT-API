<?php

namespace App\Http\Requests;

use App\Rules\ValidIdentificationNumber;
use Illuminate\Foundation\Http\FormRequest;

class ShareholderIdentityChangeRequest extends FormRequest
{
    /**
     * The identity fields eligible for a pending update.
     */
    public const ELIGIBLE_FIELDS = [
        'id_type',
        'id_value',
        'issued_on',
        'expires_on',
        'file_ref',
    ];

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
            'id_type' => 'required|in:passport,drivers_license,nin,bvn,cac_cert,other',
            'id_value' => ['required', 'string', 'max:100', new ValidIdentificationNumber($this->input('id_type'))],
            'issued_on' => 'nullable|date',
            'expires_on' => 'nullable|date',
            'file_ref' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:255',
        ];
    }

    /**
     * Only the eligible identity fields that were actually submitted.
     */
    public function proposedFields(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::ELIGIBLE_FIELDS));
    }
}
