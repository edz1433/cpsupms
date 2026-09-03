<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FundCluster;
use App\Models\PayrollBatch;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PayrollBatchService
{
    public function __construct(
        private AttendanceSummaryService $attendance,
        private PayrollComputationService $computation,
        private PayrollSignatoryService $signatories,
        private PayrollEmployeeTypeService $employeeTypes,
        private PayrollFundTypeService $fundTypes,
        private HrisTardinessSyncService $tardiness,
        private AuditLogger $audit,
    ) {}

    /**
     * One run produces a draft per payroll fund, mirroring the tabs of the manual
     * payroll workbook (PT, INC, MDS, PROJ, BUSTYPE, YEARBOOK, SUPPORT SERVICES).
     * Each employee is placed on exactly one fund, so no one is paid twice.
     */
    public function generateAll(array $data, User $user): array
    {
        return DB::transaction(function () use ($data, $user) {
            $period = PayrollPeriod::findOrFail($data['payroll_period_id']);
            $fundsByType = $this->fundTypes->mainFundClustersByType();
            $templatesByType = PayrollTemplate::query()
                ->where('is_active', true)
                ->whereIn('template_type', $fundsByType->keys()->all())
                ->get()
                ->keyBy('template_type');

            $isPartTimeRun = ($data['payroll_employee_type'] ?? null) === PayrollEmployeeTypeService::PARTTIME_PARTTIME;

            $employees = Employee::query()
                ->with(['fundCluster', 'partTimeFundCluster'])
                ->where('campus_id', $data['campus_id'])
                ->where('is_active', true)
                ->where(function ($query) use ($data, $isPartTimeRun) {
                    $query->tap(fn ($typeQuery) => $this->employeeTypes->apply($typeQuery, $data['payroll_employee_type']));

                    // A part-time post lives on the employee's own record now, so anyone
                    // carrying an hourly part-time rate joins this run too.
                    if ($isPartTimeRun) {
                        $query->orWhere('part_time_rate_per_hour', '>', 0);
                    }
                })
                ->orderBy('full_name')
                ->get();

            // Paid hourly on this run instead of from their monthly salary.
            $partTimeEmployeeIds = $isPartTimeRun
                ? $employees->filter(fn (Employee $employee) => $employee->hasPartTimeAssignment())->pluck('id')->flip()->all()
                : [];

            $runRef = 'RUN-'.now()->format('YmdHis').'-'.random_int(100, 999);
            $result = [
                'run_ref' => $runRef,
                'batches' => collect(),
                'employees' => $employees->count(),
                'unassigned' => $employees->filter(fn (Employee $employee) => ! $employee->fund_cluster_id)->count(),
                'part_time' => count($partTimeEmployeeIds),
                'skipped_funds' => [],
                'tardiness_sync' => ['status' => 'skipped'],
            ];

            if ($employees->isEmpty()) {
                return $result;
            }

            // One HRIS read covers every fund draft in the run.
            $tardinessSync = $this->tardiness->syncForPayroll($period, $data['campus_id'], $employees, $user);
            $result['tardiness_sync'] = $tardinessSync;

            $grouped = $employees->groupBy(fn (Employee $employee) => isset($partTimeEmployeeIds[$employee->id])
                ? $this->fundTypes->typeForPartTimeEmployee($employee, $fundsByType)
                : $this->fundTypes->typeForEmployee($employee, $fundsByType));
            $fundedTypes = $fundsByType->keys()->filter(fn ($type) => $grouped->has($type) && $grouped->get($type)->isNotEmpty())->values();

            foreach ($fundedTypes as $position => $type) {
                $template = $templatesByType->get($type);

                if (! $template) {
                    $result['skipped_funds'][] = $type;

                    continue;
                }

                $result['batches']->push($this->generateFundBatch(
                    $data,
                    $user,
                    $period,
                    $fundsByType->get($type),
                    $template,
                    $grouped->get($type),
                    $tardinessSync,
                    ['ref' => $runRef, 'index' => $position + 1, 'total' => $fundedTypes->count(), 'funds' => $fundedTypes->all()],
                    $partTimeEmployeeIds,
                ));
            }

            return $result;
        });
    }

    public function refreshTotals(PayrollBatch $batch): void
    {
        $lines = $batch->lines()->get();

        $batch->update([
            'total_employees' => $lines->count(),
            'total_gross' => $lines->sum('gross_income'),
            'total_deductions' => $lines->sum('total_deduction'),
            'total_net' => $lines->sum('net_amount_received'),
            'employees_with_missing_logs' => $lines->where('missing_log_status', '!=', 'No issue')->count(),
            'employees_with_approved_appeals' => $lines->where('appeal_status', 'approved')->count(),
            'employees_with_unresolved_appeals' => $lines->where('appeal_status', 'under_review')->count(),
            'employees_with_manual_adjustments' => $lines->where('has_manual_adjustment', true)->count(),
            'employees_with_missing_fund_source' => $lines->whereNull('fund_source')->count(),
            'employees_with_negative_net' => $lines->filter(fn ($line) => (float) $line->net_amount_received < 0)->count(),
        ]);
    }

    public function refreshAttendance(PayrollBatch $batch, User $user): array
    {
        return DB::transaction(function () use ($batch, $user) {
            $batch->loadMissing(['period', 'template', 'lines.employee.fundCluster']);
            $employees = $batch->lines
                ->pluck('employee')
                ->filter()
                ->unique('id')
                ->values();
            $sync = $this->tardiness->syncForPayroll($batch->period, $batch->campus_id, $employees, $user);
            $snapshot = $batch->snapshot ?? [];
            $snapshot['tardiness_sync'] = $sync + ['synced_at' => now()->toIso8601String()];
            $batch->update(['snapshot' => $snapshot]);

            if (($sync['status'] ?? null) !== 'connected') {
                return $sync;
            }

            foreach ($batch->lines as $line) {
                $employee = $line->employee;

                if (! $employee) {
                    continue;
                }

                $attendance = $this->attendance->summaryFor($employee, $batch->period);
                $attendance->setAttribute(
                    'review_items',
                    $sync['review_items_by_employee_no'][$employee->employee_no] ?? ($attendance->review_items ?? []),
                );
                $line->update($this->computation->computeLine($employee, $batch->period, $batch->template, $attendance));
            }

            $this->refreshTotals($batch);
            $this->audit->record('payroll.attendance_refreshed', $user, $batch, 'Attendance refreshed directly from the HRIS database.', [
                'updated' => $sync['updated'] ?? 0,
                'flagged' => $sync['flagged'] ?? 0,
            ]);

            return $sync;
        });
    }

    private function generateFundBatch(
        array $data,
        User $user,
        PayrollPeriod $period,
        FundCluster $fund,
        PayrollTemplate $template,
        Collection $employees,
        array $tardinessSync,
        array $run,
        array $partTimeEmployeeIds = [],
    ): PayrollBatch {
        $batch = PayrollBatch::create([
            'campus_id' => $data['campus_id'],
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $user->id,
            'batch_no' => $this->batchNo($fund),
            'status' => PayrollBatch::DRAFT,
            'remarks' => $data['remarks'] ?? null,
            'snapshot' => [
                'generated_at' => now()->toIso8601String(),
                'generated_by' => $user->name,
                'payroll_employee_type' => $data['payroll_employee_type'],
                'payroll_employee_type_label' => $this->employeeTypes->label($data['payroll_employee_type']),
                'payroll_fund_type' => $fund->payroll_template_type,
                'generation_run' => $run,
                'signatories' => $this->signatories->snapshot($data['signatories'] ?? []),
                'tardiness_sync' => $tardinessSync + ['synced_at' => now()->toIso8601String()],
            ],
        ]);

        foreach ($employees->values() as $index => $employee) {
            $attendance = $this->attendance->summaryFor($employee, $period);

            if (($tardinessSync['status'] ?? null) === 'connected') {
                $attendance->setAttribute('review_items', $tardinessSync['review_items_by_employee_no'][$employee->employee_no] ?? ($attendance->review_items ?? []));
            } else {
                $attendance->setAttribute('late_minutes', 0);
                $attendance->setAttribute('undertime_minutes', 0);
                $attendance->setAttribute('missing_log_status', 'No issue');
                $attendance->setAttribute('review_items', []);
            }

            $computed = isset($partTimeEmployeeIds[$employee->id])
                ? $this->computation->computePartTimeLine($employee, $period, $template, $attendance)
                : $this->computation->computeLine($employee, $period, $template, $attendance);

            PayrollLine::create($computed + [
                'payroll_batch_id' => $batch->id,
                'employee_id' => $employee->id,
                'line_no' => $index + 1,
            ]);
        }

        $this->refreshTotals($batch);
        $this->audit->record('payroll.generated', $user, $batch, $data['remarks'] ?? 'Draft payroll generated', [
            'campus_id' => $batch->campus_id,
            'batch_no' => $batch->batch_no,
            'fund_type' => $fund->payroll_template_type,
            'run_ref' => $run['ref'],
        ]);

        return $batch->refresh();
    }

    private function batchNo(FundCluster $fund): string
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($fund->payroll_template_type ?? $fund->code));

        return 'CPSU-'.now()->format('YmdHis').'-'.$code.'-'.random_int(100, 999);
    }
}
