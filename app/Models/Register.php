<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Company;

class Register extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'register_code',
        'name',
        'instrument_type',
        'capital_behaviour_type',
        'paid_up_capital',
        'total_units_outstanding',
        'remaining_outstanding_units',
        'narration',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'paid_up_capital' => 'decimal:6',
        'total_units_outstanding' => 'decimal:6',
        'remaining_outstanding_units' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the company that owns the register.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the share classes for the register.
     */
    public function shareClasses()
    {
        return $this->hasMany(ShareClass::class);
    }

    // TEMPORARILY COMMENTED OUT - Will enable when we create this model
    // /**
    //  * Get the shareholder register accounts for this register.
    //  */
    // public function shareholderRegisterAccounts()
    // {
    //     return $this->hasMany(ShareholderRegisterAccount::class);
    // }

    /**
     * Scope a query to only include active registers.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include closed registers.
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope a query to only include default registers.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Check if register is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if register is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Check if register is default.
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }
}
