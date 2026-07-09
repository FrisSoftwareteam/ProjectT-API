<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstrumentType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'category',
        'precision_rule',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_seeded' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Registers that use this instrument type.
     */
    public function registers()
    {
        return $this->hasMany(Register::class);
    }

    /**
     * Whether this type requires whole-number values only.
     */
    public function requiresWholeNumber(): bool
    {
        return $this->precision_rule === 'whole_number_only';
    }

    /**
     * Whether this type requires decimal values (precision set per register).
     */
    public function requiresDecimal(): bool
    {
        return $this->precision_rule === 'decimal_only';
    }

    /**
     * Whether each register using this type configures its own precision.
     */
    public function isConfigurable(): bool
    {
        return $this->precision_rule === 'configurable';
    }
}
