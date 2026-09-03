<?php

namespace App\Services;

use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Models\MissingLogAppeal;
use App\Models\PayrollPeriod;
use App\Models\PayrollTemplate;

class PayrollComputationService
{
    /**
     * Job Order daily rate is always the monthly rate over 22 days, even in a month
     * that has 23 working days. Every other type divides by the working days the
     * month actually has.
     */
    public const JOB_ORDER_WORKING_DAYS = 22;

    public function __construct(
        private StatutoryDeductionService $statutoryDeductions,
        private PayrollEmployeeTypeService $employeeTypes,
    ) {}

    /**
     * Working days are Monday to Friday across the whole month the period falls in,
     * so a semi-monthly period still divides by the month's full count.
     */
    public function workingDaysInMonth(PayrollPeriod $period): int
    {
        $date = $period->date_from->copy()->startOfMonth();
        $end = $period->date_from->copy()->endOfMonth();
        $days = 0;

        while ($date->lte($end)) {
            if (! $date->isWeekend()) {
                $days++;
            }

            $date = $date->addDay();
        }

        return max(1, $days);
    }

    /**
     * The divisor that turns a monthly rate into a daily rate depends on the employee's
     * type, not on the fund the payroll is charged to.
     */
    public function dailyRateDivisor(Employee $employee, PayrollPeriod $period): int
    {
        if ($employee->isJobOrder()) {
            return self::JOB_ORDER_WORKING_DAYS;
        }

        return $this->workingDaysInMonth($period);
    }

    public function computeLine(Employee $employee, PayrollPeriod $period, PayrollTemplate $template, AttendanceSummary $attendance): array
    {
        $approvedAppealDays = (float) MissingLogAppeal::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->where('status', 'approved')
            ->sum('credited_days');

        $unresolvedAppeals = MissingLogAppeal::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->whereIn('status', ['under_review', 'pending'])
            ->count();

        $workingDays = $this->dailyRateDivisor($employee, $period);
        $hoursPerDay = max(1, (int) $template->hours_per_day);
        $monthlySalary = (float) $employee->monthly_salary;
        $ratePerDay = round($monthlySalary / $workingDays, 2);
        $ratePerHour = round($ratePerDay / $hoursPerDay, 2);
        $ratePerMinute = round($ratePerHour / 60, 4);

        $renderedDays = min($workingDays, (float) $attendance->present_days + $approvedAppealDays);
        $absentDays = max(0, (float) $attendance->absent_days - $approvedAppealDays);
        $grossIncome = round($ratePerDay * $renderedDays, 2);
        $lateDeduction = round($ratePerMinute * (int) $attendance->late_minutes, 2);
        $undertimeDeduction = round($ratePerMinute * (int) $attendance->undertime_minutes, 2);
        $absentDeduction = round($ratePerDay * $absentDays, 2);
        $salaryDifferential = 0.00;
        $earned = round($grossIncome + $salaryDifferential - $lateDeduction - $undertimeDeduction - $absentDeduction, 2);

        $applyScheduledDeductions = $this->isFirstHalf($period);
        $statutoryDeductions = $this->statutoryDeductions->amounts($employee);
        $taxAmount = $applyScheduledDeductions ? $this->taxAmount($employee, $earned) : 0.00;
        $sss = $applyScheduledDeductions ? $statutoryDeductions['sss_amount'] : 0.00;
        $philhealth = $applyScheduledDeductions ? $statutoryDeductions['philhealth_amount'] : 0.00;
        $pagibig = $applyScheduledDeductions ? $statutoryDeductions['pagibig_amount'] : 0.00;
        $nscaMpc = $applyScheduledDeductions ? (float) $employee->nsca_mpc_amount : 0.00;
        $otherDeductions = $applyScheduledDeductions ? (float) $employee->other_deductions_amount : 0.00;
        $totalDeduction = round($taxAmount + $sss + $philhealth + $pagibig + $nscaMpc + $otherDeductions, 2);

        $net = round($earned - $totalDeduction, 2);
        $appealStatus = $approvedAppealDays > 0 ? 'approved' : ($unresolvedAppeals > 0 ? 'under_review' : null);

        return [
            'employee_no' => $employee->employee_no,
            'employee_name' => $employee->full_name,
            'designation' => $employee->designation,
            'fund_source' => $employee->fundCluster?->fund_source_name,
            'monthly_salary' => $monthlySalary,
            'rendered_days' => $renderedDays,
            'absent_days' => $absentDays,
            'late_minutes' => (int) $attendance->late_minutes,
            'undertime_minutes' => (int) $attendance->undertime_minutes,
            'rate_per_day' => $ratePerDay,
            'rate_per_hour' => $ratePerHour,
            'rate_per_minute' => $ratePerMinute,
            'gross_income' => $grossIncome,
            'late_deduction' => $lateDeduction,
            'undertime_deduction' => $undertimeDeduction,
            'absent_deduction' => $absentDeduction,
            'salary_differential' => $salaryDifferential,
            'earned_for_period' => $earned,
            'tax_amount' => $taxAmount,
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'nsca_mpc' => $nscaMpc,
            'project_deduction' => 0.00,
            'graduate_school_deduction' => 0.00,
            'other_deductions' => $otherDeductions,
            'total_deduction' => $totalDeduction,
            'net_amount_received' => $net,
            'missing_log_status' => $attendance->missing_log_status,
            'appeal_status' => $appealStatus,
            'remarks' => $this->remarks($employee, $attendance, $net, $unresolvedAppeals),
            'computed_columns' => [
                'tax_rate' => (float) $employee->tax_rate,
                'working_days' => $workingDays,
                'hours_per_day' => $hoursPerDay,
                'approved_appeal_days' => $approvedAppealDays,
                'unresolved_appeals' => $unresolvedAppeals,
                'scheduled_deductions_applied' => $applyScheduledDeductions,
                'statutory_deductions' => $statutoryDeductions,
                'attendance_review_items' => $attendance->review_items ?? [],
            ],
        ];
    }

    /**
     * Part-time pay is purely hourly: the employee is paid for the hours actually
     * rendered at their part-time rate. Absences simply mean fewer hours, so there is
     * no separate absent deduction. Statutory deductions and withholding tax are not
     * repeated here - they are taken once on the employee's regular payroll line.
     */
    public function computePartTimeLine(Employee $employee, PayrollPeriod $period, PayrollTemplate $template, AttendanceSummary $attendance): array
    {
        $approvedAppealDays = (float) MissingLogAppeal::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->where('status', 'approved')
            ->sum('credited_days');

        $unresolvedAppeals = MissingLogAppeal::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->whereIn('status', ['under_review', 'pending'])
            ->count();

        $workingDays = $this->workingDaysInMonth($period);
        $hoursPerDay = max(1, (int) $template->hours_per_day);
        $ratePerHour = round((float) $employee->part_time_rate_per_hour, 2);
        $ratePerMinute = round($ratePerHour / 60, 4);
        $ratePerDay = round($ratePerHour * $hoursPerDay, 2);

        $renderedDays = min($workingDays, (float) $attendance->present_days + $approvedAppealDays);
        $hoursRendered = round($renderedDays * $hoursPerDay, 2);
        $grossIncome = round($ratePerHour * $hoursRendered, 2);
        $lateDeduction = round($ratePerMinute * (int) $attendance->late_minutes, 2);
        $undertimeDeduction = round($ratePerMinute * (int) $attendance->undertime_minutes, 2);
        $earned = round(max(0, $grossIncome - $lateDeduction - $undertimeDeduction), 2);

        $fundCluster = $employee->partTimeFundClusterOrDefault();
        $appealStatus = $approvedAppealDays > 0 ? 'approved' : ($unresolvedAppeals > 0 ? 'under_review' : null);
        $remarks = array_filter([
            'Part-time',
            $attendance->missing_log_status !== 'No issue' ? $attendance->missing_log_status : null,
            $unresolvedAppeals > 0 ? 'Unresolved appeal' : null,
            $fundCluster ? null : 'Missing fund source',
            $earned <= 0 ? 'Zero or negative net' : null,
        ]);

        return [
            'employee_no' => $employee->employee_no,
            'employee_name' => $employee->full_name,
            'designation' => $employee->designation,
            'fund_source' => $fundCluster?->fund_source_name,
            'monthly_salary' => 0.00,
            'rendered_days' => $renderedDays,
            'absent_days' => 0,
            'late_minutes' => (int) $attendance->late_minutes,
            'undertime_minutes' => (int) $attendance->undertime_minutes,
            'rate_per_day' => $ratePerDay,
            'rate_per_hour' => $ratePerHour,
            'rate_per_minute' => $ratePerMinute,
            'gross_income' => $grossIncome,
            'late_deduction' => $lateDeduction,
            'undertime_deduction' => $undertimeDeduction,
            'absent_deduction' => 0.00,
            'salary_differential' => 0.00,
            'earned_for_period' => $earned,
            'tax_amount' => 0.00,
            'sss' => 0.00,
            'philhealth' => 0.00,
            'pagibig' => 0.00,
            'nsca_mpc' => 0.00,
            'project_deduction' => 0.00,
            'graduate_school_deduction' => 0.00,
            'other_deductions' => 0.00,
            'total_deduction' => 0.00,
            'net_amount_received' => $earned,
            'missing_log_status' => $attendance->missing_log_status,
            'appeal_status' => $appealStatus,
            'remarks' => implode('; ', $remarks),
            'computed_columns' => [
                'part_time' => true,
                'part_time_rate_per_hour' => $ratePerHour,
                'hours_per_day' => $hoursPerDay,
                'hours_rendered' => $hoursRendered,
                'working_days' => $workingDays,
                'approved_appeal_days' => $approvedAppealDays,
                'unresolved_appeals' => $unresolvedAppeals,
                'scheduled_deductions_applied' => false,
                'attendance_review_items' => $attendance->review_items ?? [],
            ],
        ];
    }
    private function taxAmount(Employee $employee, float $earned): float
    {
        $status = strtoupper((string) $employee->bir_sworn_status);

        if (str_contains($status, 'NOT REQUIRED') || str_contains($status, 'SENIOR')) {
            return 0.00;
        }

        return round($earned * (float) $employee->tax_rate, 2);
    }

    private function isFirstHalf(PayrollPeriod $period): bool
    {
        return (int) $period->date_from->day === 1 && (int) $period->date_to->day <= 15;
    }

    private function remarks(Employee $employee, AttendanceSummary $attendance, float $net, int $unresolvedAppeals): ?string
    {
        $remarks = [];

        if ($attendance->missing_log_status !== 'No issue') {
            $remarks[] = $attendance->missing_log_status;
        }

        if ($unresolvedAppeals > 0) {
            $remarks[] = 'Unresolved appeal';
        }

        if (! $employee->fund_cluster_id) {
            $remarks[] = 'Missing fund source';
        }

        if ($net <= 0) {
            $remarks[] = 'Zero or negative net';
        }

        return $remarks ? implode('; ', $remarks) : null;
    }
}
