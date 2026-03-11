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
        'trade_date',
        'sec_code',
        'identifier_type',
        'identifier_value',
        'sign',
        'volume',
        'status',
        'matched_by',
        'error_message',
        'before_qty',
        'delta_qty',
        'after_qty',
        'shareholder_id',
        'sra_id',
        'share_class_id',
        'share_transaction_id',
        'fingerprint',
        'raw_line',
        'extra_details',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'volume' => 'decimal:6',
        'before_qty' => 'decimal:6',
        'delta_qty' => 'decimal:6',
        'after_qty' => 'decimal:6',
        'extra_details' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(CscsUploadBatch::class, 'batch_id');
    }
}

