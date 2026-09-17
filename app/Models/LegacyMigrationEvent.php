<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyMigrationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['batch_id', 'event_type', 'from_status', 'to_status', 'actor_id', 'comment', 'metadata'];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}
