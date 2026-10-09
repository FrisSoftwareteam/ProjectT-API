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

    /**
     * Appended so every serialization of a change request — not just the
     * ones that go through ShareholderChangeRequestController::
     * formatChangeRequest() — carries the resubmission links.
     */
    protected $appends = ['resubmitted_from', 'resubmitted_as'];

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

    /**
     * {id, control_no} of the request this one corrected, or null if this
     * wasn't a resubmission.
     */
    public function getResubmittedFromAttribute(): ?array
    {
        if ($this->resubmitted_from_id === null) {
            return null;
        }

        $original = self::where('id', $this->resubmitted_from_id)->select('id', 'control_no')->first();

        return $original ? ['id' => $original->id, 'control_no' => $original->control_no] : null;
    }

    /**
     * {id, control_no} of the request that corrected this one, or null if
     * nothing has resubmitted against it (yet).
     */
    public function getResubmittedAsAttribute(): ?array
    {
        $newer = self::where('resubmitted_from_id', $this->id)->select('id', 'control_no')->first();

        return $newer ? ['id' => $newer->id, 'control_no' => $newer->control_no] : null;
    }
}
