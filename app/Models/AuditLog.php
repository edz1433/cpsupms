<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'campus_id',
        'event',
        'auditable_type',
        'auditable_id',
        'remarks',
        'properties',
    ];

    protected $casts = ['properties' => 'array'];
}
