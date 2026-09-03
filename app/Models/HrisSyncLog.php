<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrisSyncLog extends Model
{
    protected $fillable = [
        'user_id',
        'request_type',
        'status',
        'duration_ms',
        'error_message',
        'payload_summary',
    ];

    protected $casts = ['payload_summary' => 'array'];
}
