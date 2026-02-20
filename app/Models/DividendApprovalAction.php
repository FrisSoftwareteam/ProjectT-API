<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendApprovalAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'dividend_declaration_id',
        'step_no',
        'role_code',
        'decision',
        'actor_id',
        'comment',
        'acted_at',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
    ];

    public function declaration()
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }

    public function actor()
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}
