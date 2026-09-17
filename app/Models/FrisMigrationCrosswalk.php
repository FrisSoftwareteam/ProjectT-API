<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrisMigrationCrosswalk extends Model
{
    protected $fillable = [
        'batch_id',
        'source_table',
        'source_key',
        'source_key_hash',
        'target_table',
        'target_id',
        'row_hash',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(FrisMigrationBatch::class, 'batch_id');
    }
}
