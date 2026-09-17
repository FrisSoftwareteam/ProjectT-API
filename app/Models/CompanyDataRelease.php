<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyDataRelease extends Model
{
    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const IMPORTING = 'IMPORTING';

    public const IMPORTED = 'IMPORTED';

    public const IMPORT_FAILED = 'IMPORT_FAILED';

    public const ROLLING_BACK = 'ROLLING_BACK';

    public const ROLLED_BACK = 'ROLLED_BACK';

    public const ROLLBACK_BLOCKED = 'ROLLBACK_BLOCKED';

    protected $fillable = [
        'public_id', 'bundle_release_id', 'format_version', 'artifact_filename',
        'artifact_sha256', 'artifact_size', 'artifact_path', 'source_filename',
        'source_sha256', 'approved_snapshot_sha256', 'issuer_code', 'register_code',
        'share_class_code', 'company_id', 'register_id', 'share_class_id', 'status',
        'expected_rows', 'expected_quantity', 'imported_rows', 'rolled_back_rows',
        'imported_quantity', 'manifest', 'verification', 'reconciliation',
        'approval_snapshot_hash', 'verified_by', 'verified_at', 'approved_by',
        'approved_at', 'imported_by', 'import_started_at', 'imported_at',
        'rolled_back_by', 'rollback_started_at', 'rolled_back_at', 'failure_reason',
    ];

    protected $casts = [
        'manifest' => 'array',
        'verification' => 'array',
        'reconciliation' => 'array',
        'expected_quantity' => 'decimal:6',
        'imported_quantity' => 'decimal:6',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'import_started_at' => 'datetime',
        'imported_at' => 'datetime',
        'rollback_started_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function records()
    {
        return $this->hasMany(CompanyDataReleaseRecord::class, 'release_id');
    }

    public function approvals()
    {
        return $this->hasMany(CompanyDataReleaseApproval::class, 'release_id');
    }

    public function events()
    {
        return $this->hasMany(CompanyDataReleaseEvent::class, 'release_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
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
