<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HrisEmployeeSyncService
{
    public function __construct(
        private HrisDatabaseService $hris,
        private AuditLogger $audit,
        private PayrollEmployeeTypeService $employeeTypes,
    ) {}

    public function sync(array $filters, User $user): array
    {
        $payload = collect($filters)
            ->only(['campus_id', 'emp_status', 'emp_id'])
            ->filter(fn ($value) => $value !== null && $value !== '' && $value !== 'all')
            ->all();

        if (! $user->isUniversityWide()) {
            $payload['campus_id'] = $user->campus_id;
        }

        $result = $this->hris->employees($payload, $user);

        if (($result['status'] ?? null) !== 'connected') {
            return [
                'status' => $result['status'] ?? 'unavailable',
                'message' => $result['message'] ?? 'HRIS employee sync failed.',
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
            ];
        }

        $rows = collect($result['data']['data'] ?? []);
        $stats = DB::transaction(function () use ($rows, $user) {
            $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

            foreach ($rows as $row) {
                $employeeNo = trim((string) ($row['emp_ID'] ?? ''));
                $campusId = (int) ($row['camp_id'] ?? 0);

                if ($employeeNo === '' || ! Campus::whereKey($campusId)->exists()) {
                    $stats['skipped']++;

                    continue;
                }

                if (! $user->isUniversityWide() && $campusId !== (int) $user->campus_id) {
                    $stats['skipped']++;

                    continue;
                }

                $employee = Employee::firstOrNew(['employee_no' => $employeeNo]);
                $wasExisting = $employee->exists;

                $employee->fill([
                    'campus_id' => $campusId,
                    'fund_cluster_id' => $employee->fund_cluster_id,
                    'full_name' => $this->nameFromHris($row),
                    'office_id' => Office::resolveFromHrisDepartment($row['emp_dept'] ?? null),
                    'designation' => $row['position'] ?? null,
                    'status_id' => Status::resolveFromHrisStatus($row['emp_status'] ?? null),
                    'monthly_salary' => $employee->monthly_salary ?: 0,
                    'rate_per_day' => $employee->rate_per_day ?: 0,
                    'rate_per_hour' => $employee->rate_per_hour ?: 0,
                    'rate_per_minute' => $employee->rate_per_minute ?: 0,
                    'tax_rate' => $employee->tax_rate ?: 0,
                    'is_active' => true,
                    'last_synced_at' => now(),
                ]);
                $employee->save();

                $stats[$wasExisting ? 'updated' : 'imported']++;
            }

            return $stats;
        });

        $this->audit->record('hris.employees_synced', $user, null, 'Employees synced directly from the HRIS database.', $stats);

        return ['status' => 'connected', 'message' => 'Employee records synced directly from the HRIS database.'] + $stats;
    }

    private function nameFromHris(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return trim(collect([
            $row['lname'] ?? null,
            $row['prefix'] ?? null,
            $row['fname'] ?? null,
            $row['mname'] ?? null,
            $row['suffix'] ?? null,
        ])->filter()->implode(' '));
    }
}
