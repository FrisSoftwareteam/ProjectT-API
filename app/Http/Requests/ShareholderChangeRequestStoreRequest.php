<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareholderChangeRequestStoreRequest extends FormRequest
{
    /**
     * The shareholder profile fields eligible for a pending update.
     */
    public const ELIGIBLE_FIELDS = [
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'date_of_birth',
        'sex',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
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
        $shareholderId = $this->route('shareholder');
        $shareholderId = is_object($shareholderId) ? $shareholderId->id : $shareholderId;

        return [
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:100',
            'middle_name' => 'sometimes|nullable|string|max:100',
            'email' => [
                'sometimes',
                'nullable',
                'email',
                Rule::unique('shareholders', 'email')->ignore($shareholderId),
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:32',
                Rule::unique('shareholders', 'phone')->ignore($shareholderId),
            ],
            'date_of_birth' => 'sometimes|nullable|date',
            'sex' => 'sometimes|nullable|in:male,female,other',
            'next_of_kin_name' => 'sometimes|nullable|string|max:255',
            'next_of_kin_phone' => 'sometimes|nullable|string|max:32',
            'next_of_kin_relationship' => 'sometimes|nullable|string|max:100',

            // Proposed change to the shareholder's primary residential address.
            'address' => 'sometimes|array',
            'address.address_line1' => 'required_with:address|string|max:255',
            'address.address_line2' => 'nullable|string|max:255',
            'address.city' => 'nullable|string|max:100',
            'address.state' => 'nullable|string|max:100',
            'address.postal_code' => 'nullable|string|max:20',
            'address.country' => 'nullable|string|max:100',

            'reason' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered to another shareholder.',
            'phone.unique' => 'This phone number is already registered to another shareholder.',
            'sex.in' => 'Gender must be one of: male, female, or other.',
            'address.address_line1.required_with' => 'Address line 1 is required when proposing an address change.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $submittedFields = array_intersect_key($this->all(), array_flip(self::ELIGIBLE_FIELDS));

            if (empty($submittedFields) && ! $this->has('address')) {
                $validator->errors()->add(
                    'fields',
                    'At least one field must be provided to submit a pending update.'
                );
            }
        });
    }

    /**
     * Only the eligible shareholder-table fields that were actually submitted.
     */
    public function proposedFields(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::ELIGIBLE_FIELDS));
    }

    /**
     * The proposed primary-address change, or null if none was submitted.
     */
    public function proposedAddress(): ?array
    {
        $address = $this->validated('address');

        if (! is_array($address)) {
            return null;
        }

        return array_filter($address, fn ($value) => $value !== null);
    }
}
