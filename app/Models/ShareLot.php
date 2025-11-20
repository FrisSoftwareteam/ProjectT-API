<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShareLot extends Model
{
    protected $table = 'share_lots';

    protected $fillable = [
        'sra_id',
        'share_class_id',
        'lot_ref',
        'source_type',
        'quantity',
        'acquired_at',
        'status',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'acquired_at' => 'datetime',
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
