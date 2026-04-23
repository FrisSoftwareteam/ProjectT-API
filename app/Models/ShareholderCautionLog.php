<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ShareholderCautionLog
 *
 * Immutable audit trail for every caution action.
 * Rows are NEVER updated or deleted.
 */
class ShareholderCautionLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'caution_id',
        'shareholder_id',
        'sra_id',
        'action',
        'caution_type',
        'instruction_source',
        'reason',
        'scope',
        'company_id',
        'caution_share_class_id',
        'actor_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function caution()
    {
        return $this->belongsTo(ShareholderCaution::class, 'caution_id');
    }

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }

    public function sra()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function cautionShareClass()
    {
        return $this->belongsTo(ShareClass::class, 'caution_share_class_id');
    }

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}