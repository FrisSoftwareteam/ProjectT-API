<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendEntitlement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'entitlement_run_id',
        'dividend_declaration_id',
        'register_account_id',
        'share_class_id',
        'eligible_shares',
        'gross_amount',
        'tax_amount',
        'net_amount',
        'is_payable',
        'ineligibility_reason',
    ];

    protected $casts = [
        'eligible_shares' => 'decimal:6',
        'gross_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'is_payable' => 'boolean',
    ];

    public function run()
    {
        return $this->belongsTo(DividendEntitlementRun::class, 'entitlement_run_id');
    }

    public function declaration()
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }

    public function registerAccount()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'register_account_id');
    }

    public function shareClass()
    {
        return $this->belongsTo(ShareClass::class);
    }
}


