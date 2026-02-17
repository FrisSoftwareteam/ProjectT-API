<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendDeclaration extends Model
{
    use HasFactory;

    protected $fillable = [
        'dividend_declaration_no',
        'company_id',
        'register_id',
        'supplementary_of_declaration_id',
        'period_label',
        'description',
        'initiator',
        'action_type',
        'declaration_method',
        'rate_per_share',
        'announcement_date',
        'record_date',
        'payment_date',
        'exclude_caution_accounts',
        'require_active_bank_mandate',
        'status',
        'current_approval_step',
        'total_gross_amount',
        'total_tax_amount',
        'total_net_amount',
        'rounding_residue',
        'eligible_shareholders_count',
        'is_frozen',
        'submitted_at',
        'approved_at',
        'live_at',
        'rejected_at',
        'archived_at',
        'archived_from_status',
        'rejection_reason',
        'created_by',
        'submitted_by',
        'approved_by',
        'rejected_by',
    ];

    protected $casts = [
        'rate_per_share' => 'decimal:6',
        'announcement_date' => 'date',
        'record_date' => 'date',
        'payment_date' => 'date',
        'exclude_caution_accounts' => 'boolean',
        'require_active_bank_mandate' => 'boolean',
        'current_approval_step' => 'integer',
        'total_gross_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'total_net_amount' => 'decimal:2',
        'rounding_residue' => 'decimal:6',
        'is_frozen' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'live_at' => 'datetime',
        'rejected_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    // Relationships
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function supplementaryOf()
    {
        return $this->belongsTo(self::class, 'supplementary_of_declaration_id');
    }

    public function supplementaryDeclarations()
    {
        return $this->hasMany(self::class, 'supplementary_of_declaration_id');
    }

    public function shareClasses()
    {
        return $this->belongsToMany(ShareClass::class, 'dividend_declaration_share_classes')
                    ->withTimestamps();
    }

    public function entitlementRuns()
    {
        return $this->hasMany(DividendEntitlementRun::class);
    }

    public function approvalActions()
    {
        return $this->hasMany(DividendApprovalAction::class, 'dividend_declaration_id');
    }

    public function delegations()
    {
        return $this->hasMany(DividendApprovalDelegation::class, 'dividend_declaration_id');
    }

    public function workflowEvents()
    {
        return $this->hasMany(DividendWorkflowEvent::class);
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(AdminUser::class, 'submitted_by');
    }

    public function verifier()
    {
        return $this->belongsTo(AdminUser::class, 'verified_by');
    }

    public function approver()
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(AdminUser::class, 'rejected_by');
    }

    // Status helpers
    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'SUBMITTED';
    }

    public function isVerified(): bool
    {
        return false;
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function isLive(): bool
    {
        return $this->status === 'LIVE';
    }

    public function isQueryRaised(): bool
    {
        return $this->status === 'QUERY_RAISED';
    }

    public function isArchived(): bool
    {
        return $this->status === 'ARCHIVED';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }

    public function canBeEdited(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function canBeDeleted(): bool
    {
        return $this->status === 'DRAFT';
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', 'SUBMITTED');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    public function scopeByRegister($query, $registerId)
    {
        return $query->where('register_id', $registerId);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
