<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareholderChangeApproval extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'change_request_id',
        'level_no',
        'decision',
        'decided_by',
        'decided_at',
        'remarks',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function changeRequest()
    {
        return $this->belongsTo(ShareholderChangeRequest::class, 'change_request_id');
    }

    public function decider()
    {
        return $this->belongsTo(AdminUser::class, 'decided_by');
    }
}
