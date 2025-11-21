<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareTransaction extends Model
{
    use HasFactory;

    /**
     * Disable updated_at timestamp since table only has created_at.
     *
     * @var bool
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'sra_id',
        'share_class_id',
        'tx_type',
        'quantity',
        'tx_ref',
        'tx_date',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'decimal:6',
        'tx_date' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the share class that owns the transaction.
     */
    public function shareClass()
    {
        return $this->belongsTo(ShareClass::class);
    }

    /**
     * Get the shareholder register account that owns the transaction.
     */
    public function shareholderRegisterAccount()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }

    /**
     * Get the admin user who created the transaction.
     */
    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}