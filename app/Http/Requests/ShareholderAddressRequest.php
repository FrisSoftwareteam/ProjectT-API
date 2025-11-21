<?php

namespace App\Http\Requests;

use App\Models\Shareholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
class ShareholderAddressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shareholder_id' => 'required|exists:shareholders,id',
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
            $shareholderId = $this->input('shareholder_id');
            if (! $shareholderId) {
                return;
            }

            $shareholder = Shareholder::find($shareholderId);
            if (! $shareholder) {
                return;
            }

            if ($this->boolean('is_primary') && $shareholder->hasActiveAddress()) {
                $validator->errors()->add('shareholder_id', 'Shareholder already has an active address.');
            }
        });
    }
}
