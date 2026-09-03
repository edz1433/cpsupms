<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissingLogAppeal extends Model
{
    protected $fillable = [
        'employee_id',
        'payroll_period_id',
        'created_by',
        'reviewed_by',
        'attendance_date',
        'missing_log_status',
        'status',
        'credited_days',
        'reason',
        'review_remarks',
        'attachment_path',
        'reviewed_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'credited_days' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];
}
