<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CscsApprovalAction extends Model
{
    protected $fillable = [
        'batch_id', 'revision', 'step_no', 'role_code', 'decision',
        'actor_id', 'comment', 'context', 'acted_at',
    ];

    protected $casts = ['context' => 'array', 'acted_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}
