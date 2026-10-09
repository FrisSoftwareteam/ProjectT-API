<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareholderChangeRequest extends Model
{
    use HasFactory;

    const CREATED_AT = 'submitted_at';

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'shareholder_id',
        'request_type',
        'payload_old',
        'payload_new',
        'reason',
        'resubmitted_from_id',
        'status',
        'control_no',
        'submitted_by',
        'submitted_at',
        'info_requested_type',
        'info_requested_note',
        'info_requested_by',
        'info_requested_at',
    ];

    protected $casts = [
        'payload_old' => 'array',
        'payload_new' => 'array',
        'submitted_at' => 'datetime',
        'updated_at' => 'datetime',
        'info_requested_at' => 'datetime',
    ];

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }

    public function submitter()
    {
        return $this->belongsTo(AdminUser::class, 'submitted_by');
    }

    public function infoRequestedBy()
    {
        return $this->belongsTo(AdminUser::class, 'info_requested_by');
    }

    public function approvals()
    {
        return $this->hasMany(ShareholderChangeApproval::class, 'change_request_id');
    }

    public function resubmittedFrom()
    {
        return $this->belongsTo(self::class, 'resubmitted_from_id');
    }

    public function resubmittedAs()
    {
        return $this->hasOne(self::class, 'resubmitted_from_id');
    }
}
