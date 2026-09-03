<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundCluster extends Model
{
    protected $fillable = [
        'campus_id',
        'code',
        'name',
        'payroll_template_type',
        'fund_source_name',
        'default_signatories',
        'default_deduction_rules',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_signatories' => 'array',
        'default_deduction_rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }
}
