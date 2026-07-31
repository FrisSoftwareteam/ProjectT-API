<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShareholderCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'default_holder_type',
        'requires_joint_holders',
        'requires_review',
        'is_active',
        'source_system',
        'description',
        'metadata',
    ];

    protected $casts = [
        'requires_joint_holders' => 'boolean',
        'requires_review' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function registerAccounts()
    {
        return $this->hasMany(ShareholderRegisterAccount::class);
    }

    public function isCompatibleWith(string $holderType): bool
    {
        return $this->default_holder_type === null
            || $this->default_holder_type === $holderType;
    }
}
