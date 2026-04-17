<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProbateCase extends Model
{
    protected $table = 'probate_cases';

    protected $fillable = [
        'shareholder_id',
        'case_type',
        'court_ref',
        'grant_date',
        'document_ref',
        'original_first_name',
        'original_last_name',
        'original_middle_name',
        'original_full_name',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'grant_date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class, 'shareholder_id');
    }

    public function beneficiaries()
    {
        return $this->hasMany(ProbateBeneficiary::class, 'probate_case_id');
    }

    public function representatives()
    {
        return $this->hasMany(EstateCaseRepresentative::class, 'probate_case_id');
    }
}
