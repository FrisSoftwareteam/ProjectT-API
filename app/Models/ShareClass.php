<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShareClass extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'register_id',
        'class_code',
        'currency',
        'par_value',
        'description',
        'withholding_tax_rate',
        'is_caution_class',     // true only for the system caution class
    ];

    protected $casts = [
        'par_value'            => 'decimal:6',
        'withholding_tax_rate' => 'decimal:4',
        'is_caution_class'     => 'boolean',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
        'deleted_at'           => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * Get the register that owns the share class.
     */
    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    /**
     * Get the caution records linked to this share class.
     * Only relevant when is_caution_class = true.
     */
    public function cautions()
    {
        return $this->hasMany(ShareholderCaution::class, 'caution_share_class_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to return only the caution share class.
     */
    public function scopeCautionClass($query)
    {
        return $query->where('is_caution_class', true);
    }

    // ── Helpers / Accessors ───────────────────────────────────────────────────

    /**
     * Check whether this is the system caution class.
     */
    public function isCautionClass(): bool
    {
        return (bool) $this->is_caution_class;
    }

    /**
     * Get formatted par value with currency symbol.
     */
    public function getFormattedParValueAttribute(): string
    {
        return number_format((float) $this->par_value, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted withholding tax rate as a percentage string.
     */
    public function getFormattedTaxRateAttribute(): string
    {
        if (is_null($this->withholding_tax_rate)) {
            return '0.00%';
        }
        return number_format((float) $this->withholding_tax_rate, 2) . '%';
    }

    /**
     * Calculate withholding tax on a given gross amount.
     *
     * @param float $amount The dividend or income amount
     * @return float The calculated tax amount
     */
    public function calculateWithholdingTax(float $amount): float
    {
        if (is_null($this->withholding_tax_rate) || $this->withholding_tax_rate <= 0) {
            return 0.00;
        }
        return round(($amount * $this->withholding_tax_rate) / 100, 2);
    }

    /**
     * Calculate net amount after withholding tax deduction.
     *
     * @param float $amount The gross dividend or income amount
     * @return float The net amount after tax
     */
    public function calculateNetAmount(float $amount): float
    {
        $tax = $this->calculateWithholdingTax($amount);
        return round($amount - $tax, 2);
    }
}