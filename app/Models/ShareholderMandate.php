<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Shareholder;


class ShareholderMandate extends Model
{

    protected $table = 'shareholder_bank_mandates';

    protected $fillable = [
        'shareholder_id',
        'bank_name',
        'account_name',
        'account_number',
        'bvn',
        'status',
        'verified_by',
        'verified_at',
    ];

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }


}
