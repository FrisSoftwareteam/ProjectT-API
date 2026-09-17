<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrisMigrationProfile extends Model
{
    protected $fillable = [
        'batch_id',
        'source_row_number',
        'register_code',
        'account_number',
        'source_key_hash',
        'row_hash',
        'status',
        'holder_type',
        'normalized_name',
        'normalized_email',
        'normalized_mobile',
        'source_holdings',
        'source_data',
        'normalized_data',
        'errors',
        'shareholder_id',
        'address_id',
        'mandate_id',
        'sra_id',
        'published_at',
    ];

    protected $casts = [
        'source_holdings' => 'decimal:6',
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
