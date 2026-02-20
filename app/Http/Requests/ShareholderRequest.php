<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        return [
            // 'account_no' => 'required|string|max:20|unique:shareholders,account_no',
            'holder_type' => 'required|in:individual,corporate',
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:shareholders,email',
            'phone' => 'required|string|max:32|unique:shareholders,phone',
            'date_of_birth' => 'nullable|date',
            'sex' => 'nullable|in:male,female,other',
            'rc_number' => 'nullable|string|max:50',
            'nin' => 'nullable|string|max:20',
            'bvn' => 'nullable|string|max:20',
            'tax_id' => 'nullable|string|max:50',
            'next_of_kin_name' => 'nullable|string|max:255',
            'next_of_kin_phone' => 'nullable|string|max:32',
            'next_of_kin_relationship' => 'nullable|string|max:100',
            'status' => 'required|in:active,dormant,deceased,closed',
        ];
    }
}
