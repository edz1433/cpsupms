<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'campus_id',
        'name',
        'date_from',
        'date_to',
        'period_type',
        'payroll_type',
        'is_locked',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'is_locked' => 'boolean',
    ];

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }
}
