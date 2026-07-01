<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareholderImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'uploaded_by',
        'status',
        'source_filename',
        'stored_path',
        'summary',
    ];

    protected $casts = [
        'summary' => 'array',
    ];

    public function rows()
    {
        return $this->hasMany(ShareholderImportRow::class, 'batch_id');
    }
}
