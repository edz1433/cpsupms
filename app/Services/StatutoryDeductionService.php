<?php

namespace App\Services;

use App\Models\Employee;

class StatutoryDeductionService
{
    public const PHILHEALTH_DIRECT = 'direct';

    public const PHILHEALTH_INDIRECT = 'indirect';

    public function amounts(Employee $employee): array
    {
        return [
            'sss_amount' => $this->sssAmount($employee),
            'philhealth_amount' => $this->philhealthAmount($employee),
            'pagibig_amount' => $this->pagibigAmount($employee),
        ];
    }

    public function sssAmount(Employee $employee): float
    {
        $salary = (float) $employee->monthly_salary;

        if ($salary <= 0 || $this->hasStatus($employee, ['NO SSS', 'SENIOR'])) {
            return 0.00;
        }

        $monthlySalaryCredit = min(max($salary, 5000), 35000);

        return round($monthlySalaryCredit * 0.05, 2);
    }

    public function philhealthAmount(Employee $employee): float
    {
        if ($employee->philhealth_contribution_type === self::PHILHEALTH_INDIRECT) {
            return 0.00;
        }

        $salary = (float) $employee->monthly_salary;

        if ($salary <= 0) {
            return 0.00;
        }

        $premiumBase = min(max($salary, 10000), 100000);

        return round($premiumBase * 0.05, 2);
    }

    public function pagibigAmount(Employee $employee): float
    {
        $salary = (float) $employee->monthly_salary;

        if ($salary <= 0) {
            return 0.00;
        }

        $rate = $salary <= 1500 ? 0.01 : 0.02;

        return round(min($salary * $rate, 200), 2);
    }

    private function hasStatus(Employee $employee, array $needles): bool
    {
        $status = strtoupper(collect([
            $employee->tax_status,
            $employee->bir_sworn_status,
            $employee->employment_type,
        ])->filter()->implode(' '));

        foreach ($needles as $needle) {
            if (str_contains($status, $needle)) {
                return true;
            }
        }

        return false;
    }
}
