<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyDataReleaseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'release_id', 'event_type', 'from_status', 'to_status', 'actor_id',
        'comment', 'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function release()
    {
        return $this->belongsTo(CompanyDataRelease::class, 'release_id');
    }

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}
