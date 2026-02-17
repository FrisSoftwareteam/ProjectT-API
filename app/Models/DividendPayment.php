<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'dividend_payment_no',
        'entitlement_id',
        'payout_mode',
        'bank_mandate_id',
        'paid_at',
        'paid_ref',
        'status',
        'reissued_from_id',
        'reissue_reason',
        'created_by',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function entitlement()
    {
        return $this->belongsTo(DividendEntitlement::class, 'entitlement_id');
    }

    public function bankMandate()
    {
        return $this->belongsTo(ShareholderMandate::class, 'bank_mandate_id');
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function reissuedFrom()
    {
        return $this->belongsTo(self::class, 'reissued_from_id');
    }

    public function reissues()
    {
        return $this->hasMany(self::class, 'reissued_from_id');
    }
}
