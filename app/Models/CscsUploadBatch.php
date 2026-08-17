<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CscsUploadBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'uploaded_by',
        'register_id',
        'status',
        'uploaded_files',
        'summary',
        'workflow_status',
        'revision',
        'batch_type',
        'source_batch_id',
        'business_reference',
        'description',
        'snapshot_hash',
        'reconciliation',
        'risk_flags',
        'required_approval_steps',
        'current_approval_step',
        'reconciled_by',
        'reconciled_at',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'posted_by',
        'posting_started_at',
        'posted_at',
        'failure_reason',
    ];

    protected $casts = [
        'uploaded_files' => 'array',
        'summary' => 'array',
        'reconciliation' => 'array',
        'risk_flags' => 'array',
        'required_approval_steps' => 'array',
        'revision' => 'integer',
        'current_approval_step' => 'integer',
        'reconciled_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'posting_started_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(CscsUploadRow::class, 'batch_id');
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function approvalActions()
    {
        return $this->hasMany(CscsApprovalAction::class, 'batch_id');
    }

    public function events()
    {
        return $this->hasMany(CscsWorkflowEvent::class, 'batch_id');
    }

    public function snapshots()
    {
        return $this->hasMany(CscsBatchSnapshot::class, 'batch_id');
    }

    public function sourceBatch()
    {
        return $this->belongsTo(self::class, 'source_batch_id');
    }

    public function relatedBatches()
    {
        return $this->hasMany(self::class, 'source_batch_id');
    }
}
