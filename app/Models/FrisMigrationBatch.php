<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrisMigrationBatch extends Model
{
    public const CREATED = 'CREATED';
    public const STAGING = 'STAGING';
    public const STAGED = 'STAGED';
    public const VALIDATED = 'VALIDATED';
    public const APPROVED = 'APPROVED';
    public const PUBLISHING = 'PUBLISHING';
    public const PUBLISHED = 'PUBLISHED';
    public const FAILED = 'FAILED';

    protected $fillable = [
        'public_id',
        'source_filename',
        'source_path',
        'source_sha256',
        'source_size',
        'status',
        'revision',
        'register_code',
        'company_name',
        'expected_profile_rows',
        'expected_unit_rows',
        'staged_profile_rows',
        'staged_unit_rows',
        'valid_profile_rows',
        'valid_unit_rows',
        'error_profile_rows',
        'error_unit_rows',
        'expected_profile_holdings',
        'expected_unit_quantity',
        'staged_profile_holdings',
        'staged_unit_quantity',
        'profile_summary',
        'unit_summary',
        'reconciliation',
        'approval_snapshot_hash',
        'created_by',
        'validated_by',
        'validated_at',
        'approved_by',
        'approved_at',
        'published_by',
        'publishing_started_at',
        'published_at',
        'failure_reason',
    ];

    protected $casts = [
        'profile_summary' => 'array',
        'unit_summary' => 'array',
        'reconciliation' => 'array',
        'expected_profile_holdings' => 'decimal:6',
        'expected_unit_quantity' => 'decimal:6',
        'staged_profile_holdings' => 'decimal:6',
        'staged_unit_quantity' => 'decimal:6',
        'validated_at' => 'datetime',
        'approved_at' => 'datetime',
        'publishing_started_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function profiles()
    {
        return $this->hasMany(FrisMigrationProfile::class, 'batch_id');
    }

    public function units()
    {
        return $this->hasMany(FrisMigrationUnit::class, 'batch_id');
    }
}
