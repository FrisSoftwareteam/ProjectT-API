<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EstateCaseRepresentative extends Model
{
    use HasFactory;

    protected $fillable = [
        'probate_case_id',
        'representative_type',
        'full_name',
        'id_type',
        'id_value',
        'email',
        'phone',
        'address',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function probateCase()
    {
        return $this->belongsTo(ProbateCase::class, 'probate_case_id');
    }
}
