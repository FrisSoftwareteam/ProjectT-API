<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareholderMergeEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'primary_shareholder_id',
        'duplicate_shareholder_id',
        'verification_basis',
        'reason',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}

