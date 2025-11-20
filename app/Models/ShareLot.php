<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareLot extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'sra_id',
        'share_class_id',
        'lot_ref',
        'source_type',
        'quantity',
        'acquired_at',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'decimal:6',
        'acquired_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the share class that owns the lot.
     */
    public function shareClass()
    {
        return $this->belongsTo(ShareClass::class);
    }

    /**
     * Get the shareholder register account that owns the lot.
     */
    public function shareholderRegisterAccount()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }
}