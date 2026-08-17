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
        'status',
        'control_no',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'payload_old' => 'array',
        'payload_new' => 'array',
        'submitted_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shareholder()
    {
        return $this->belongsTo(Shareholder::class);
    }

    public function submitter()
    {
        return $this->belongsTo(AdminUser::class, 'submitted_by');
    }

    public function approvals()
    {
        return $this->hasMany(ShareholderChangeApproval::class, 'change_request_id');
    }
}
