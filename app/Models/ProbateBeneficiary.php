<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProbateBeneficiary extends Model
{
    protected $table = 'probate_beneficiaries';

    protected $fillable = [
        'probate_case_id',
        'beneficiary_shareholder_id',
        'beneficiary_name',
        'relationship',
        'share_class_id',
        'sra_id',
        'quantity',
        'transfer_status',
        'executed_by',
        'executed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'executed_at' => 'datetime',
    ];

    public function probateCase()
    {
        return $this->belongsTo(ProbateCase::class, 'probate_case_id');
    }

    public function beneficiaryShareholder()
    {
        return $this->belongsTo(Shareholder::class, 'beneficiary_shareholder_id');
    }
}
