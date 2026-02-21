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
}
