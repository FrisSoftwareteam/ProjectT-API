<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareTransferEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_shareholder_id',
        'to_shareholder_id',
        'from_sra_id',
        'to_sra_id',
        'share_class_id',
        'quantity',
        'tx_ref',
        'document_ref',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'metadata' => 'array',
    ];
}

