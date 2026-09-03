<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PayrollBatch extends Model
{
    public const DRAFT = 'Draft';

    public const SUBMITTED = 'Submitted to University Payroll';

    public const UNDER_REVIEW = 'Under University Payroll Review';

    public const RETURNED = 'Returned for Correction';

    public const RESUBMITTED = 'Corrected and Resubmitted';

    public const APPROVED = 'Approved for Printing';

    public const PRINTED = 'Printed';

    public const CANCELLED = 'Cancelled';

    protected $fillable = [
        'campus_id',
        'payroll_period_id',
        'payroll_template_id',
        'fund_cluster_id',
        'created_by',
        'current_reviewer_id',
        'batch_no',
        'status',
        'total_employees',
        'total_gross',
        'total_deductions',
        'total_net',
        'employees_with_missing_logs',
        'employees_with_approved_appeals',
        'employees_with_unresolved_appeals',
        'employees_with_manual_adjustments',
        'employees_with_missing_fund_source',
        'employees_with_negative_net',
        'remarks',
        'submitted_at',
        'approved_at',
        'printed_at',
        'printed_by',
        'snapshot',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'printed_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }

    public function period()
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function template()
    {
        return $this->belongsTo(PayrollTemplate::class, 'payroll_template_id');
    }

    public function fundCluster()
    {
        return $this->belongsTo(FundCluster::class);
    }

    public function lines()
    {
        return $this->hasMany(PayrollLine::class)->orderBy('line_no');
    }

    public function reviews()
    {
        return $this->hasMany(PayrollReview::class)->latest();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isUniversityWide()) {
            return $query;
        }

        return $query->where('campus_id', $user->campus_id);
    }
}
