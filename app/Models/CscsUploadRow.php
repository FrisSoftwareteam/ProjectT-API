<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CscsUploadRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'file_type',
        'source_filename',
        'row_number',
        'tran_no',
        'tran_seq',
        'transaction_group_key',
        'trade_date',
        'sec_code',
        'identifier_type',
        'identifier_value',
        'sign',
        'volume',
        'status',
        'resolution_status',
        'exception_code',
        'matched_by',
        'match_method',
        'error_message',
        'before_qty',
        'delta_qty',
        'after_qty',
        'shareholder_id',
        'sra_id',
        'proposed_sra_id',
        'share_class_id',
        'proposed_share_class_id',
        'share_transaction_id',
        'fingerprint',
        'raw_line',
        'extra_details',
        'proposed_before_qty',
        'proposed_delta_qty',
        'proposed_after_qty',
        'actual_before_qty',
        'actual_after_qty',
        'replay_key',
        'resolved_by',
        'resolved_at',
        'resolution_reason',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'volume' => 'decimal:6',
        'before_qty' => 'decimal:6',
        'delta_qty' => 'decimal:6',
        'after_qty' => 'decimal:6',
        'extra_details' => 'array',
        'proposed_before_qty' => 'decimal:6',
        'proposed_delta_qty' => 'decimal:6',
        'proposed_after_qty' => 'decimal:6',
        'actual_before_qty' => 'decimal:6',
        'actual_after_qty' => 'decimal:6',
        'resolved_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(CscsUploadBatch::class, 'batch_id');
    }
}
