<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SharePosition extends Model
{
    protected $table = 'share_positions';

    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'sra_id',
        'share_class_id',
        'quantity',
        'holding_mode',
    ];


    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'decimal:6',
        'last_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sra()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }

    public function shareClass()
    {
        return $this->belongsTo(ShareClass::class, 'share_class_id');
    }

    /**
     * Get the shareholder register account that owns the position.
     */
    public function shareholderRegisterAccount()
    {
        return $this->belongsTo(ShareholderRegisterAccount::class, 'sra_id');
    }
}
