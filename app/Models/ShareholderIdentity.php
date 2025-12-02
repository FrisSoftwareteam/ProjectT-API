<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Shareholder;

class ShareholderIdentity extends Model
{
    protected $table = 'shareholder_identities';
    
    protected $fillable = [
        'shareholder_id',
        'id_type',
        'id_value',
        'issued_on',
        'expires_on',
        'verified_status',
        'verified_by',
        'verified_at',
        'file_ref',
    ];

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }
    
}
