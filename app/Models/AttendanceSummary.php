<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSummary extends Model
{
    protected $fillable = [
        'employee_id',
        'payroll_period_id',
        'present_days',
        'absent_days',
        'late_minutes',
        'undertime_minutes',
        'missing_log_status',
        'review_items',
        'last_synced_at',
    ];

    protected $casts = [
        'present_days' => 'decimal:2',
        'absent_days' => 'decimal:2',
        'review_items' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
