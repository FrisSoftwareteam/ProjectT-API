<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShareholderRegisterAccount extends Model
{
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

    public static function generateAccountNumber($shareholderId)
    {
        // Simple account number generator: SRA-{shareholderId}-{timestamp}
        return 'SRA-' . $shareholderId . '-' . substr(time(), -6);
    }

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class, 'shareholder_id');
    }
}
