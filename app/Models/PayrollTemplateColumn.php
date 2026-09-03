<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollTemplateColumn extends Model
{
    protected $fillable = [
        'payroll_template_id',
        'fund_cluster_id',
        'column_key',
        'display_label',
        'column_group',
        'type',
        'direction',
        'formula_expression',
        'manual_input_allowed',
        'default_value',
        'is_required',
        'show_in_draft',
        'show_in_final',
        'sort_order',
        'width',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'manual_input_allowed' => 'boolean',
        'default_value' => 'decimal:2',
        'is_required' => 'boolean',
        'show_in_draft' => 'boolean',
        'show_in_final' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];
}
