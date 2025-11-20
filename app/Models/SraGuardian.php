<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SraGuardian extends Model
{
    protected $table = 'sra_guardians';

    protected $fillable = [
        'sra_id',
        'guardian_shareholder_id',
        'guardian_name',
        'guardian_contact',
        'document_ref',
        'valid_from',
        'valid_to',
        'verified_status',
        'verified_by',
        'verified_at',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'verified_at' => 'datetime',
    ];

    public function sra()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }

    public function guardianShareholder()
    {
        return $this->belongsTo(Shareholder::class, 'guardian_shareholder_id');
    }
}
