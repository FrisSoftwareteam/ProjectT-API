<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddShareRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'share_class_id' => ['required', 'exists:share_classes,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'source_type' => ['required', 'in:allotment,bonus,rights,transfer_in,demat_in,certificate_deposit'],
            'lot_ref' => ['nullable', 'string', 'max:64'],
            'acquired_at' => ['nullable', 'date'],
            'holding_mode' => ['nullable', 'in:demat,paper'],
            'register_id' => ['nullable', 'exists:registers,id'],
        ];
    }
}
