<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrisMigrationUnit extends Model
{
    protected $fillable = [
        'batch_id',
        'source_row_number',
        'fris_unit_id',
        'register_code',
        'account_number',
        'cert_number',
        'source_key_hash',
        'row_hash',
        'status',
        'source_units',
        'issue_date',
        'source_status',
        'source_verified',
        'source_claimed',
        'source_stop',
        'source_data',
        'normalized_data',
        'errors',
        'profile_stage_id',
        'share_lot_id',
        'share_transaction_id',
        'published_at',
    ];

    protected $casts = [
        'source_units' => 'decimal:6',
        'issue_date' => 'datetime',
        'source_data' => 'array',
        'normalized_data' => 'array',
        'errors' => 'array',
        'published_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(FrisMigrationBatch::class, 'batch_id');
    }
}
