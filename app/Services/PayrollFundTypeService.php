<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FundCluster;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class PayrollFundTypeService
{
    public const TYPES = [
        'PT',
        'INC',
        'MDS',
        'PROJ',
        'BUSTYPE',
        'YEARBOOK',
        'SUPPORT SERVICES',
    ];

    /**
     * Employees synced from HRIS without a fund cluster still have to be paid, so they
     * land on this fund and are flagged as "Missing fund source" for correction.
     */
    public const FALLBACK_TYPE = 'MDS';

    public function mainFundClusters(): Collection
    {
        $this->ensureMainFundClusters();

        return FundCluster::query()
            ->whereNull('campus_id')
            ->whereIn('code', self::TYPES)
            ->whereColumn('code', 'payroll_template_type')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The seven workbook tabs keyed by payroll template type, in report order.
     */
    public function mainFundClustersByType(): SupportCollection
    {
        return $this->mainFundClusters()->keyBy('payroll_template_type');
    }

    /**
     * Which of the seven fund payrolls an employee belongs to. The workbook puts every
     * person on exactly one tab, so this resolves to a single type per employee.
     */
    public function typeForEmployee(Employee $employee, SupportCollection $fundsByType): string
    {
        $type = $employee->fundCluster?->payroll_template_type;

        return $type !== null && $fundsByType->has($type) ? $type : self::FALLBACK_TYPE;
    }

    /**
     * Part-time pay can be charged to a different fund than the employee's regular
     * salary, falling back to their own fund cluster when none was chosen.
     */
    public function typeForPartTimeEmployee(Employee $employee, SupportCollection $fundsByType): string
    {
        $type = $employee->partTimeFundClusterOrDefault()?->payroll_template_type;

        return $type !== null && $fundsByType->has($type) ? $type : self::FALLBACK_TYPE;
    }
    public function ensureMainFundClusters(): void
    {
        foreach (self::TYPES as $index => $type) {
            FundCluster::updateOrCreate(
                ['campus_id' => null, 'code' => $type],
                [
                    'name' => $type.' Payroll',
                    'payroll_template_type' => $type,
                    'fund_source_name' => $type,
                    'default_signatories' => [
                        'prepared_by' => 'Campus Payroll Administrator',
                        'checked_by' => 'University Payroll Administrator',
                    ],
                    'default_deduction_rules' => ['tax_configurable' => true],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ]
            );
        }
    }
}
