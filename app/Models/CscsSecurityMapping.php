<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CscsSecurityMapping extends Model
{
    protected $fillable = ['security_code', 'register_id', 'share_class_id', 'is_active', 'created_by', 'updated_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function shareClass()
    {
        return $this->belongsTo(ShareClass::class);
    }
}
