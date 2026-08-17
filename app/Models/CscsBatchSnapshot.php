<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CscsBatchSnapshot extends Model
{
    protected $fillable = [
        'batch_id',
        'revision',
        'snapshot_hash',
        'payload',
        'reconciliation',
        'risk_flags',
        'source_files',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'payload' => 'array',
        'reconciliation' => 'array',
        'risk_flags' => 'array',
        'source_files' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(CscsUploadBatch::class, 'batch_id');
    }
}
