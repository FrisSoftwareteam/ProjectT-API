<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shareholder extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'shareholders';
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
    ];

    /**
     * Get the addresses for the shareholder.
     */
    public function addresses()
    {
        return $this->hasMany(ShareholderAddress::class);
    }

    /**
     * Check if shareholder has an active address.
     */
    public function hasActiveAddress(): bool
    {
        return $this->addresses()
            ->where('is_primary', true)
            ->exists();
    }

    /**
     * Get the mandates for the shareholder.
     */
    public function mandates()
    {
        return $this->hasMany(ShareholderMandate::class, 'shareholder_id', 'id');
    }

    /**
     * Get the identities for the shareholder.
     */
    public function identities()
    {
        return $this->hasMany(ShareholderIdentity::class, 'shareholder_id', 'id');
    }

    /**
     * Get the register accounts for the shareholder.
     */
    public function registerAccounts()
    {
        return $this->hasMany(ShareholderRegisterAccount::class);
    }

    /**
     * Get holdings for the shareholder across register accounts.
     */
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

    /**
     * Get certificate lots for the shareholder across register accounts.
     */
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
}
