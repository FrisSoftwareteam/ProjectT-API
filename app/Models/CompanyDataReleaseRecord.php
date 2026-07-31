<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyDataReleaseRecord extends Model
{
    protected $fillable = [
        'release_id', 'source_row_number', 'idempotency_key', 'row_hash',
        'source_account_number', 'category_code', 'holder_type', 'quantity',
        'holding_mode', 'status', 'shareholder_id', 'address_id', 'sra_id',
        'position_id', 'imported_at', 'rolled_back_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'imported_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function release()
    {
        return $this->belongsTo(CompanyDataRelease::class, 'release_id');
    }
}
