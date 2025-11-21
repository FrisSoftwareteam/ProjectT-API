<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserActivityLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'user_activity_logs';

    protected $fillable = [
        'user_id',
        'action',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(AdminUser::class, 'user_id');
    }
}
