<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'campus_id',
        'fund_cluster_id',
        'employee_no',
        'full_name',
        'office',
        'designation',
        'employment_type',
        'salary_grade',
        'monthly_salary',
        'rate_per_day',
        'rate_per_hour',
        'rate_per_minute',
        'tax_rate',
        'tax_status',
        'bir_sworn_status',
        'sss_amount',
        'philhealth_amount',
        'philhealth_contribution_type',
        'pagibig_amount',
        'nsca_mpc_amount',
        'other_deductions_amount',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'rate_per_day' => 'decimal:2',
        'rate_per_hour' => 'decimal:2',
        'rate_per_minute' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }

    public function fundCluster()
    {
        return $this->belongsTo(FundCluster::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isUniversityWide()) {
            return $query;
        }

        return $query->where('campus_id', $user->campus_id);
    }
}
