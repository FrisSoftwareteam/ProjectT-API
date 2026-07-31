<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyDataReleaseApproval extends Model
{
    protected $fillable = [
        'release_id', 'decision', 'actor_id', 'comment', 'snapshot_hash', 'acted_at',
    ];

    protected $casts = ['acted_at' => 'datetime'];

    public function release()
    {
        return $this->belongsTo(CompanyDataRelease::class, 'release_id');
    }

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}
