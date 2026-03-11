<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IpoOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'register_id',
        'share_class_id',
        'approved_units',
        'allotted_units',
        'status',
        'offer_ref',
        'created_by',
        'approved_by',
        'approved_at',
        'finalized_at',
    ];

    protected $casts = [
        'approved_units' => 'decimal:6',
        'allotted_units' => 'decimal:6',
        'approved_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function allotments()
    {
        return $this->hasMany(IpoOfferAllotment::class, 'offer_id');
    }
}

