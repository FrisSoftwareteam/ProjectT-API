<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shareholder extends Model
{
    protected $table = 'shareholders';
    
    protected $fillable = [
        'account_no',
        'holder_type',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'date_of_birth',
        'rc_number',
        'nin',
        'bvn',
        'tax_id',
        'status',
    ];

    public function addresses()
    {
        return $this->hasMany(ShareholderAddress::class);
    }

    public function hasActiveAddress(): bool
    {
        return $this->addresses()
            ->where('is_primary', true)
            ->exists();
    }

    public function mandates()
    {
        return $this->hasMany(ShareholderMandate::class,'shareholder_id','id');
    }

    public function identities()
    {
        return $this->hasMany(ShareholderIdentity::class,'shareholder_id','id');
    }
}
