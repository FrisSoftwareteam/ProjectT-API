<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShareholderAddress extends Model
{
    protected $fillable = [
        'shareholder_id',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'is_primary',
        'valid_from',
        'valid_to',
    ];

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }
}
