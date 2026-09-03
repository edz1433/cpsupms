<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'computed_columns' => 'array',
        'has_manual_adjustment' => 'boolean',
    ];

    public function batch()
    {
        return $this->belongsTo(PayrollBatch::class, 'payroll_batch_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
