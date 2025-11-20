<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharePosition extends Model
{
    protected $table = 'share_positions';

    protected $fillable = [
        'sra_id',
        'share_class_id',
        'quantity',
        'holding_mode',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
    ];

    public function sra()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }

    public function shareClass()
    {
        return $this->belongsTo(ShareClass::class, 'share_class_id');
    }
}
