<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendDeclaration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'register_id',
        'period_label',
        'description',
        'action_type',
        'declaration_method',
        'rate_per_share',
        'announcement_date',
        'record_date',
        'payment_date',
        'exclude_caution_accounts',
        'require_active_bank_mandate',
        'status',
        'total_gross_amount',
        'total_tax_amount',
        'total_net_amount',
        'rounding_residue',
        'eligible_shareholders_count',
        'submitted_at',
        'verified_at',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'created_by',
        'submitted_by',
        'verified_by',
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
        'total_gross_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'total_net_amount' => 'decimal:2',
        'rounding_residue' => 'decimal:6',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
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

    public function shareClasses()
    {
        return $this->belongsToMany(ShareClass::class, 'dividend_declaration_share_classes')
                    ->withTimestamps();
    }

    public function entitlementRuns()
    {
        return $this->hasMany(DividendEntitlementRun::class);
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
        return $this->status === 'VERIFIED';
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
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