<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * ShareholderCaution Table Model 
 */
class ShareholderCaution extends Model
{
    protected $fillable = [
        'shareholder_id',
        'sra_id',
        'caution_share_class_id',
        'scope',
        'company_id',
        'caution_type',
        'instruction_source',
        'reason',
        'effective_date',
        'removed_at',
        'removal_reason',
        'removed_by',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'removed_at'     => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];


    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }

    public function sra()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }

    public function cautionShareClass()
    {
        return $this->belongsTo(ShareClass::class, 'caution_share_class_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function removedBy()
    {
        return $this->belongsTo(AdminUser::class, 'removed_by');
    }

    public function logs()
    {
        return $this->hasMany(ShareholderCautionLog::class, 'caution_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function scopeRemoved(Builder $query): Builder
    {
        return $query->whereNotNull('removed_at');
    }

    public function scopeForSra(Builder $query, int $sraId): Builder
    {
        return $query->where('sra_id', $sraId);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }


    public function isActive(): bool
    {
        return is_null($this->removed_at);
    }

    public function isGlobal(): bool
    {
        return $this->scope === 'global';
    }

    public function isCompanyLevel(): bool
    {
        return $this->scope === 'company';
    }
}