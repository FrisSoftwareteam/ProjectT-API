<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SraExternalIdentifier extends Model
{
    use HasFactory;

    protected $fillable = [
        'sra_id',
        'identifier_type',
        'identifier_value',
        'source',
        'created_by',
    ];

    public function registerAccount()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }
}

