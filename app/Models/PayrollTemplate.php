<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollTemplate extends Model
{
    protected $fillable = [
        'code',
        'name',
        'template_type',
        'working_days',
        'hours_per_day',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function columns()
    {
        return $this->hasMany(PayrollTemplateColumn::class)->orderBy('sort_order');
    }
}
