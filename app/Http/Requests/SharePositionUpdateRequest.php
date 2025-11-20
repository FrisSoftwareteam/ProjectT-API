<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SharePositionUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0'],
            'holding_mode' => ['required', 'in:demat,paper'],
        ];
    }
}
