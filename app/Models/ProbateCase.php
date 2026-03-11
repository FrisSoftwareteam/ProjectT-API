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
        'executor_name',
        'document_ref',
        'case_status',
        'estate_shareholder_id',
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

    public function estateShareholder()
    {
        return $this->belongsTo(Shareholder::class, 'estate_shareholder_id');
    }

    public function representatives()
    {
        return $this->hasMany(EstateCaseRepresentative::class, 'probate_case_id');
    }
}
