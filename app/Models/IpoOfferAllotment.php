<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IpoOfferAllotment extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'shareholder_id',
        'quantity',
        'post_status',
        'posted_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'posted_at' => 'datetime',
    ];

    public function offer()
    {
        return $this->belongsTo(IpoOffer::class, 'offer_id');
    }
}

