<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shareholder extends Model
{
    use HasFactory;

    protected $table = 'shareholders';
 
    protected $fillable = [
        'account_no',
        'holder_type',
        'first_name',
        'last_name',
        'middle_name',
        'full_name',
        'email',
        'phone',
        'date_of_birth',
        'sex',
        'rc_number',
        'nin',
        'bvn',
        'tax_id',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'status',
    ];
    protected $casts = [
        'date_of_birth' => 'date',
    ];

    protected $appends = [
        'is_cautioned',
        'active_cautions_count',
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
        return $this->hasMany(ShareholderMandate::class, 'shareholder_id', 'id');
    }

    public function identities()
    {
        return $this->hasMany(ShareholderIdentity::class, 'shareholder_id', 'id');
    }

    public function registerAccounts()
    {
        return $this->hasMany(ShareholderRegisterAccount::class);
    }

    public function holdings()
    {
        return $this->hasManyThrough(
            SharePosition::class,
            ShareholderRegisterAccount::class,
            'shareholder_id',
            'sra_id',
            'id',
            'id'
        );
    }

    public function certificates()
    {
        return $this->hasManyThrough(
            ShareLot::class,
            ShareholderRegisterAccount::class,
            'shareholder_id',
            'sra_id',
            'id',
            'id'
        );
    }

    public function activeCautions()
    {
        return $this->hasMany(ShareholderCaution::class, 'shareholder_id')
                    ->whereNull('removed_at');
    }

    public function getIsCautionedAttribute(): bool
    {
        if ($this->relationLoaded('activeCautions')) {
            return $this->activeCautions->isNotEmpty();
        }
        return $this->activeCautions()->exists();
    }

    public function getActiveCautionsCountAttribute(): int
    {
        if ($this->relationLoaded('activeCautions')) {
            return $this->activeCautions->count();
        }
        return $this->activeCautions()->count();
    }
}