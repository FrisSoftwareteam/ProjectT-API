<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ShareholderChangeRequestDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'shareholder_change_request_id',
        'category',
        'document_type',
        'file_name',
        'file_path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected $appends = ['url'];

    public function changeRequest()
    {
        return $this->belongsTo(ShareholderChangeRequest::class, 'shareholder_change_request_id');
    }

    public function uploader()
    {
        return $this->belongsTo(AdminUser::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
