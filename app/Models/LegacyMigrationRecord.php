<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyMigrationRecord extends Model
{
    protected $fillable = [
        'batch_id', 'source_row_number', 'source_key_hash', 'row_hash', 'idempotency_key',
        'source_account_number', 'target_account_no', 'target_email', 'target_phone',
        'holder_type', 'category_code', 'quantity', 'holding_mode', 'status',
        'normalized_data', 'errors', 'shareholder_id', 'address_id', 'sra_id',
        'position_id', 'published_at', 'rolled_back_at',
    ];

    protected $casts = [
        'normalized_data' => 'array',
        'errors' => 'array',
        'quantity' => 'decimal:6',
        'published_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(LegacyMigrationBatch::class, 'batch_id');
    }
}
