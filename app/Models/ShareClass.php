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
    ];

    protected $casts = [
        'par_value' => 'decimal:6',
        'withholding_tax_rate' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the register that owns the share class.
     */
    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    // TEMPORARILY COMMENTED OUT - Will enable when we create these models
    // /**
    //  * Get the share positions for this share class.
    //  */
    // public function sharePositions()
    // {
    //     return $this->hasMany(SharePosition::class);
    // }

    // /**
    //  * Get the share lots for this share class.
    //  */
    // public function shareLots()
    // {
    //     return $this->hasMany(ShareLot::class);
    // }

    // /**
    //  * Get the share transactions for this share class.
    //  */
    // public function shareTransactions()
    // {
    //     return $this->hasMany(ShareTransaction::class);
    // }

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Get formatted par value.
     */
    public function getFormattedParValueAttribute(): string
    {
        return number_format((float)$this->par_value, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted withholding tax rate as percentage.
     */
    public function getFormattedTaxRateAttribute(): string
    {
        if (is_null($this->withholding_tax_rate)) {
            return '0.00%';
        }
        return number_format((float)$this->withholding_tax_rate, 2) . '%';
    }

    /**
     * Calculate withholding tax on a given amount.
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
     * Calculate net amount after withholding tax.
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