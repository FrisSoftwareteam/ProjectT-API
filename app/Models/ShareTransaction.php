<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareTransaction extends Model
{
    protected $table = 'share_transactions';

    public $timestamps = false; // table uses created_at sometimes; we manage timestamps explicitly

    protected $fillable = [
        'sra_id',
        'share_class_id',
        'tx_type',
        'quantity',
        'tx_ref',
        'tx_date',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'tx_date' => 'datetime',
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
