<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareholderImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'row_number',
        'status',
        'raw_data',
        'errors',
        'error_message',
        'shareholder_id',
        'shareholder_register_account_id',
        'share_position_id',
        'share_lot_id',
        'share_transaction_id',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'errors' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(ShareholderImportBatch::class, 'batch_id');
    }
}
