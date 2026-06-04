<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareholderRequest extends FormRequest
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
        $shareholderRoute = $this->route('shareholder');
        $shareholderId = is_object($shareholderRoute) ? $shareholderRoute->id : $shareholderRoute;
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            // 'account_no' => 'required|string|max:20|unique:shareholders,account_no',
            'holder_type' => $required . '|in:individual,corporate',
            'first_name' => $required . '|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:100',
            'middle_name' => 'sometimes|nullable|string|max:100',
            'email' => [
                $required,
                'email',
                Rule::unique('shareholders', 'email')->ignore($shareholderId),
            ],
            'phone' => [
                $required,
                'string',
                'max:32',
                Rule::unique('shareholders', 'phone')->ignore($shareholderId),
            ],
            'date_of_birth' => 'sometimes|nullable|date',
            'sex' => 'sometimes|nullable|in:male,female,other',
            'rc_number' => 'sometimes|nullable|string|max:50',
            'nin' => 'sometimes|nullable|string|max:20',
            'bvn' => 'sometimes|nullable|string|max:20',
            'tax_id' => 'sometimes|nullable|string|max:50',
            'next_of_kin_name' => 'sometimes|nullable|string|max:255',
            'next_of_kin_phone' => 'sometimes|nullable|string|max:32',
            'next_of_kin_relationship' => 'sometimes|nullable|string|max:100',
            'status' => $required . '|in:active,dormant,deceased,closed',
        ];
    }
    public function messages(): array
    {
        return [
            'holder_type.required' => 'Holder type is required.',
            'holder_type.in'       => 'Holder type must be either "individual" or "corporate".',
            'first_name.required' => 'First name is required.',
            'first_name.string'   => 'First name must be a valid text value.',
            'first_name.max'      => 'First name must not exceed 255 characters.',
            'last_name.string' => 'Last name must be a valid text value.',
            'last_name.max'    => 'Last name must not exceed 100 characters.',
            'middle_name.string' => 'Middle name must be a valid text value.',
            'middle_name.max'    => 'Middle name must not exceed 100 characters.',
            'email.required' => 'Email address is required.',
            'email.email'    => 'Please enter a valid email address.',
            'email.unique'   => 'This email address is already registered to another shareholder.',
            'phone.required' => 'Phone number is required.',
            'phone.string'   => 'Phone number must be a valid text value.',
            'phone.max'      => 'Phone number must not exceed 32 characters.',
            'phone.unique'   => 'This phone number is already registered to another shareholder.',
            'date_of_birth.date' => 'Date of birth must be a valid date.',
            'sex.in' => 'Gender must be one of: male, female, or other.',
            'rc_number.string' => 'RC number must be a valid text value.',
            'rc_number.max'    => 'RC number must not exceed 50 characters.',
            'nin.string' => 'NIN must be a valid text value.',
            'nin.max'    => 'NIN must not exceed 20 characters.',
            'bvn.string' => 'BVN must be a valid text value.',
            'bvn.max'    => 'BVN must not exceed 20 characters.',
            'tax_id.string' => 'Tax ID must be a valid text value.',
            'tax_id.max'    => 'Tax ID must not exceed 50 characters.',
            'next_of_kin_name.string'         => 'Next of kin name must be a valid text value.',
            'next_of_kin_name.max'            => 'Next of kin name must not exceed 255 characters.',
            'next_of_kin_phone.string'        => 'Next of kin phone must be a valid text value.',
            'next_of_kin_phone.max'           => 'Next of kin phone must not exceed 32 characters.',
            'next_of_kin_relationship.string' => 'Next of kin relationship must be a valid text value.',
            'next_of_kin_relationship.max'    => 'Next of kin relationship must not exceed 100 characters.',
            'status.required' => 'Status is required.',
            'status.in'       => 'Status must be one of: active, dormant, deceased, or closed.',
        ];
    }
}