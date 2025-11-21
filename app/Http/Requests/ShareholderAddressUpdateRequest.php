<?php

namespace App\Http\Requests;

use App\Models\ShareholderAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShareholderAddressUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'is_primary' => 'required|boolean',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->boolean('is_primary')) {
                return;
            }

            $addressParam = $this->route('address');
            if (! $addressParam) {
                return;
            }

            $address = $addressParam instanceof ShareholderAddress
                ? $addressParam
                : ShareholderAddress::find($addressParam);
            if (! $address) {
                return;
            }

            $shareholder = $address->shareholder;
            if (! $shareholder) {
                return;
            }

            if ($shareholder->addresses()
                ->where('is_primary', true)
                ->where('id', '!=', $address->id)
                ->exists()) {
                $validator->errors()->add('is_primary', 'Shareholder already has an active address.');
            }
        });
    }
}

