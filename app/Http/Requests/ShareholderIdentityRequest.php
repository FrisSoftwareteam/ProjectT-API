<?php

namespace App\Http\Requests;

use App\Models\Shareholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareholderIdentityRequest extends FormRequest
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
        $shareholder = $this->route('shareholder');
        $shareholderId = $shareholder instanceof Shareholder
            ? $shareholder->getKey()
            : $shareholder;

        return [
            // Kept optional for existing clients; ownership comes from the nested route.
            'shareholder_id' => ['sometimes', 'integer', Rule::in([$shareholderId])],
            'id_type' => 'required|in:passport,drivers_license,nin,bvn,cac_cert,other',
            'id_value' => 'required|string|max:100',
            'issued_on' => 'nullable|date',
            'expires_on' => 'nullable|date',
            'verified_status' => 'required|in:pending,verified,rejected',
            'verified_by' => 'nullable|exists:admin_users,id',
            'verified_at' => 'nullable|date',
            'file_ref' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'shareholder_id.in' => 'The shareholder ID must match the shareholder in the URL.',
        ];
    }

    public function attributes(): array
    {
        return [
            'shareholder_id' => 'shareholder',
            'id_type' => 'identity type',
        ];
    }
}
