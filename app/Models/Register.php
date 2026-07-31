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
        'instrument_type_id',
        'capital_behaviour_type',
        'paid_up_capital',
        'total_units_outstanding',
        'remaining_outstanding_units',
        'unit_precision_type',
        'decimal_precision',
        'narration',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'paid_up_capital' => 'decimal:6',
        'total_units_outstanding' => 'decimal:6',
        'remaining_outstanding_units' => 'decimal:6',
        'decimal_precision'   => 'integer',
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

    /**
     * Get the instrument type for this register.
     */
    public function instrumentType()
    {
        return $this->belongsTo(InstrumentType::class);
    }

    /**
     * Get the shareholder register accounts for this register.
     */
    public function shareholderRegisterAccounts()
    {
        return $this->hasMany(ShareholderRegisterAccount::class);
    }

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

    /**
     * Check if this register's units must be whole numbers only.
     */
    public function isWholeNumberOnly(): bool
    {
        return $this->unit_precision_type === 'whole_number';
    }

    /**
     * Get the maximum number of decimal places allowed for units on this register.
     */
    public function getMaxDecimalPlaces(): int
    {
        return $this->isWholeNumberOnly() ? 0 : (int) ($this->decimal_precision ?? 2);
    }

    /**
     * Validate that a given value conforms to this register's unit precision rules.
     */
    public function validateUnitPrecision($value): bool
    {
        $strValue = (string) $value;
        $maxDecimals = $this->getMaxDecimalPlaces();
        if (! str_contains($strValue, '.')) {
            return true;
        }
        $decimalPart = substr($strValue, strpos($strValue, '.') + 1);
        $decimalPart = rtrim($decimalPart, '0');
        return strlen($decimalPart) <= $maxDecimals;
    }
}
