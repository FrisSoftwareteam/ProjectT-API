<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendEntitlementRun extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'dividend_declaration_id',
        'run_type',
        'run_status',
        'computed_at',
        'computed_by',
        'total_gross_amount',
        'total_tax_amount',
        'total_net_amount',
        'rounding_residue',
        'eligible_shareholders_count',
        'error_message',
    ];

    protected $casts = [
        'computed_at' => 'datetime',
        'total_gross_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'total_net_amount' => 'decimal:2',
        'rounding_residue' => 'decimal:6',
    ];

    public function declaration()
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }

    public function entitlements()
    {
        return $this->hasMany(DividendEntitlement::class, 'entitlement_run_id');
    }

    public function computedBy()
    {
        return $this->belongsTo(AdminUser::class, 'computed_by');
    }

    public function isPreview(): bool
    {
        return $this->run_type === 'PREVIEW';
    }

    public function isFrozen(): bool
    {
        return $this->run_type === 'FROZEN';
    }

    public function isCompleted(): bool
    {
        return $this->run_status === 'COMPLETED';
    }
}

