<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CscsApprovalPolicy extends Model
{
    protected $fillable = [
        'name', 'is_active', 'checker_roles', 'additional_approval_quantity',
        'additional_approval_roles', 'checker_can_post', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'checker_roles' => 'array',
        'additional_approval_quantity' => 'decimal:6',
        'additional_approval_roles' => 'array',
        'checker_can_post' => 'boolean',
    ];
}
