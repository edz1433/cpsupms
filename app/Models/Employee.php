<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'campus_id',
        'fund_cluster_id',
        'office_id',
        'employee_no',
        'full_name',
        'designation',
        'employment_type',
        'status_id',
        'salary_grade',
        'monthly_salary',
        'rate_per_day',
        'rate_per_hour',
        'rate_per_minute',
        'part_time_rate_per_hour',
        'part_time_fund_cluster_id',
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
        'part_time_rate_per_hour' => 'decimal:2',
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

    public function partTimeFundCluster()
    {
        return $this->belongsTo(FundCluster::class, 'part_time_fund_cluster_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Employment status label, read through the statuses table so exports, payroll
     * computation and views keep working with a plain string.
     */
    public function getEmploymentTypeAttribute(): string
    {
        return $this->status?->status_name ?? 'Regular';
    }

    /**
     * Accepts a status id or any known label, so HRIS sync and existing callers can
     * set the status without resolving the row themselves.
     */
    public function setEmploymentTypeAttribute(mixed $value): void
    {
        $this->attributes['status_id'] = Status::resolveFromHrisStatus($value);
    }

    public function isJobOrder(): bool
    {
        return (int) $this->status_id === Status::JOB_ORDER;
    }

    /**
     * Display name of the assigned office, kept as a plain string so payroll
     * exports and views can use it wherever the old free-text column was read.
     */
    public function getOfficeNameAttribute(): ?string
    {
        return $this->office?->office_name;
    }

    /**
     * A part-time hourly rate above zero is what marks an employee as also holding
     * a part-time post, so no duplicate employee record is needed for one.
     */
    public function hasPartTimeAssignment(): bool
    {
        return (float) $this->part_time_rate_per_hour > 0;
    }

    /**
     * Part-time pay falls back to the employee's own fund cluster unless a separate
     * one was chosen for the part-time post.
     */
    public function partTimeFundClusterOrDefault(): ?FundCluster
    {
        return $this->partTimeFundCluster ?: $this->fundCluster;
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isUniversityWide()) {
            return $query;
        }

        return $query->where('campus_id', $user->campus_id);
    }
}
