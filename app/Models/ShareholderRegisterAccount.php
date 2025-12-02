<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class ShareholderRegisterAccount extends Model
{
    use HasFactory;
    protected $table = 'shareholder_register_accounts';

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

    public static function generateAccountNumber($shareholderId)
    {
        // Simple account number generator: SRA-{shareholderId}-{timestamp}
        return 'SRA-' . $shareholderId . '-' . substr(time(), -6);
    }

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class, 'shareholder_id');
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
}