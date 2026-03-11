<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CscsUploadBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'uploaded_by',
        'register_id',
        'status',
        'uploaded_files',
        'summary',
    ];

    protected $casts = [
        'uploaded_files' => 'array',
        'summary' => 'array',
    ];

    public function rows()
    {
        return $this->hasMany(CscsUploadRow::class, 'batch_id');
    }
}

