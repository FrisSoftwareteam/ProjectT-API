<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyMigrationBatch extends Model
{
    public const CREATED = 'CREATED';

    public const STAGING = 'STAGING';

    public const STAGED = 'STAGED';

    public const VALIDATED = 'VALIDATED';

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const PUBLISHING = 'PUBLISHING';

    public const PUBLISHING_FAILED = 'PUBLISHING_FAILED';

    public const PUBLISHED = 'PUBLISHED';

    public const ROLLING_BACK = 'ROLLING_BACK';

    public const ROLLED_BACK = 'ROLLED_BACK';

    public const FAILED = 'FAILED';

    public const ROLLBACK_BLOCKED = 'ROLLBACK_BLOCKED';

    public const CANCELLED = 'CANCELLED';

    protected $fillable = [
        'public_id', 'package_key', 'package_version', 'register_id', 'share_class_id',
        'source_filename', 'source_sha256', 'source_size', 'status', 'revision', 'attempt_no',
        'expected_rows', 'expected_quantity', 'staged_rows', 'valid_rows', 'error_rows',
        'published_rows', 'rolled_back_rows', 'staged_quantity', 'config_snapshot',
        'reconciliation', 'approval_snapshot_hash', 'created_by', 'validated_by',
        'validated_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'published_by', 'publishing_started_at', 'published_at', 'rolled_back_by',
        'rollback_started_at', 'rolled_back_at', 'failure_reason',
    ];

    protected $casts = [
        'config_snapshot' => 'array',
        'reconciliation' => 'array',
        'expected_quantity' => 'decimal:6',
        'staged_quantity' => 'decimal:6',
        'attempt_no' => 'integer',
        'validated_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'publishing_started_at' => 'datetime',
        'published_at' => 'datetime',
        'rollback_started_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function records()
    {
        return $this->hasMany(LegacyMigrationRecord::class, 'batch_id');
    }

    public function approvals()
    {
        return $this->hasMany(LegacyMigrationApproval::class, 'batch_id');
    }

    public function events()
    {
        return $this->hasMany(LegacyMigrationEvent::class, 'batch_id');
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function shareClass()
    {
        return $this->belongsTo(ShareClass::class);
    }
}
