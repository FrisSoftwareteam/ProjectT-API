<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CscsWorkflowEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id', 'event_type', 'from_status', 'to_status', 'actor_id',
        'comment', 'metadata', 'created_at',
    ];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}
