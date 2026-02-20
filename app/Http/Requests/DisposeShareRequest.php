<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisposeShareRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'register_id' => ['required', 'exists:registers,id'],
            'share_class_id' => ['required', 'exists:share_classes,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'tx_type' => ['required', 'in:transfer_out,demat_out,cancellation'],
            'tx_ref' => ['nullable', 'string', 'max:64'],
            'tx_date' => ['nullable', 'date'],
            'close_position_if_zero' => ['nullable', 'boolean'],
        ];
    }
}
