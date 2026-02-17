<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DividendApprovalDelegation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'dividend_declaration_id',
        'role_code',
        'reliever_user_id',
        'assigned_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function declaration()
    {
        return $this->belongsTo(DividendDeclaration::class, 'dividend_declaration_id');
    }

    public function reliever()
    {
        return $this->belongsTo(AdminUser::class, 'reliever_user_id');
    }

    public function assigner()
    {
        return $this->belongsTo(AdminUser::class, 'assigned_by');
    }
}
