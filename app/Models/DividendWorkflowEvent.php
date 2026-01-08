<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class DividendWorkflowEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'dividend_declaration_id',
        'event_type',
        'actor_id',
        'note',
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