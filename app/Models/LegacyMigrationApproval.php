<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyMigrationApproval extends Model
{
    protected $fillable = ['batch_id', 'revision', 'decision', 'actor_id', 'comment', 'snapshot_hash', 'acted_at'];

    protected $casts = ['acted_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}
