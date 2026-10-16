<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function scopeForActorRole($query, string $role)
    {
        return $query->where(function ($builder) use ($role) {
            $builder->whereJsonContains('metadata->actor->roles', $role)
                ->orWhere(function ($legacy) use ($role) {
                    $legacy->whereNull('metadata->actor->roles')
                        ->whereHas('user.roles', fn ($roles) => $roles->where('name', $role));
                });
        });
    }

    public function user()
    {
        return $this->belongsTo(AdminUser::class, 'user_id');
    }
}
