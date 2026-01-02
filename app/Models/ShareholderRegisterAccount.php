<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareholderRegisterAccount extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'shareholder_register_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shareholder_id',
        'register_id',
        'shareholder_no',
        'chn',
        'cscs_account_no',
        'residency_status',
        'kyc_level',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the shareholder that owns the account.
     */
    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }

    /**
     * Get the register that owns the account.
     */
    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    /**
     * Get the share positions for this account.
     */
    public function sharePositions()
    {
        return $this->hasMany(SharePosition::class, 'sra_id');
    }

    /**
     * Get the share lots for this account.
     */
    public function shareLots()
    {
        return $this->hasMany(ShareLot::class, 'sra_id');
    }

    /**
     * Get the share transactions for this account.
     */
    public function shareTransactions()
    {
        return $this->hasMany(ShareTransaction::class, 'sra_id');
    }

    /**
     * Get the dividend entitlements for this account.
     */
    public function dividendEntitlements()
    {
        return $this->hasMany(DividendEntitlement::class, 'register_account_id');
    }
}