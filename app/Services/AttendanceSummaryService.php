<?php

namespace App\Services;

use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Models\PayrollPeriod;

class AttendanceSummaryService
{
    public function summaryFor(Employee $employee, PayrollPeriod $period): AttendanceSummary
    {
        return AttendanceSummary::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'payroll_period_id' => $period->id,
            ],
            [
                'present_days' => $period->date_from->diffInWeekdays($period->date_to) + 1,
                'absent_days' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'missing_log_status' => 'No issue',
            ]
        );
    }
}
