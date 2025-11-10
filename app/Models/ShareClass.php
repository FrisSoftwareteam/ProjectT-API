<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShareClass extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'register_id',
        'class_code',
        'currency',
        'par_value',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'par_value' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the register that owns the share class.
     */
    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    /**
     * Get the share positions for this share class.
     */
    public function sharePositions()
    {
        return $this->hasMany(SharePosition::class);
    }

    /**
     * Get the share lots for this share class.
     */
    public function shareLots()
    {
        return $this->hasMany(ShareLot::class);
    }

    /**
     * Get the share transactions for this share class.
     */
    public function shareTransactions()
    {
        return $this->hasMany(ShareTransaction::class);
    }

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Get formatted par value.
     */
    public function getFormattedParValueAttribute(): string
    {
        return number_format($this->par_value, 2) . ' ' . $this->currency;
    }
}