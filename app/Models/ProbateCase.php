<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProbateCase extends Model
{
    protected $table = 'probate_cases';

    protected $fillable = [
        'shareholder_id',
        'court_ref',
        'executor_name',
        'document_ref',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
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
}
