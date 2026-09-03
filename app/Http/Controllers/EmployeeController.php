<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\Employee;
use App\Models\FundCluster;
use App\Services\AuditLogger;
use App\Services\HrisEmployeeSyncService;
use App\Services\PayrollEmployeeTypeService;
use App\Services\StatutoryDeductionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request, PayrollEmployeeTypeService $employeeTypes)
    {
        $user = auth()->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')->where('is_active', true)],
            'fund_cluster_id' => ['nullable', 'integer', Rule::exists('fund_clusters', 'id')->where('is_active', true)],
            'employment_type' => ['nullable', Rule::in(array_keys(PayrollEmployeeTypeService::TYPES))],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
        ]);
        $filters['q'] = trim($filters['q'] ?? '');
        $filters['status'] = $filters['status'] ?? 'active';

        if (! $user->isUniversityWide()) {
            $filters['campus_id'] = $user->campus_id;
        }

        $campuses = Campus::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $fundClusters = FundCluster::query()
            ->when(! $user->isUniversityWide(), fn ($query) => $query->where(function ($query) use ($user) {
                $query->whereNull('campus_id')
                    ->orWhere('campus_id', $user->campus_id);
            }))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('fund_source_name')
            ->get();

        $baseQuery = Employee::query()->visibleTo($user);
        $employees = (clone $baseQuery)
            ->with(['campus', 'fundCluster'])
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $search = $filters['q'];

                $query->where(function ($query) use ($search) {
                    $query->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")
                        ->orWhere('office', 'like', "%{$search}%")
                        ->orWhere('designation', 'like', "%{$search}%")
                        ->orWhere('employment_type', 'like', "%{$search}%")
                        ->orWhere('salary_grade', 'like', "%{$search}%")
                        ->orWhereHas('campus', fn ($campusQuery) => $campusQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%"))
                        ->orWhereHas('fundCluster', fn ($fundQuery) => $fundQuery
                            ->where('code', 'like', "%{$search}%")
                            ->orWhere('fund_source_name', 'like', "%{$search}%"));
                });
            })
            ->when(! empty($filters['campus_id']), fn ($query) => $query->where('campus_id', $filters['campus_id']))
            ->when(! empty($filters['fund_cluster_id']), fn ($query) => $query->where('fund_cluster_id', $filters['fund_cluster_id']))
            ->when(! empty($filters['employment_type']), fn ($query) => $employeeTypes->apply($query, $filters['employment_type']))
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        return view('employees.index', [
            'employees' => $employees,
            'campuses' => $campuses,
            'fundClusters' => $fundClusters,
            'employmentTypes' => $employeeTypes->options(),
            'filters' => $filters,
            'totalEmployees' => (clone $baseQuery)->count(),
        ]);
    }

    public function update(Request $request, Employee $employee, AuditLogger $audit, StatutoryDeductionService $statutoryDeductions, PayrollEmployeeTypeService $employeeTypes)
    {
        $user = $request->user();

        abort_unless($user->canManagePayroll(), 403);
        abort_unless($user->isUniversityWide() || (int) $employee->campus_id === (int) $user->campus_id, 403);

        $validated = $request->validate([
            'campus_id' => ['required', Rule::exists('campuses', 'id')->where('is_active', true)],
            'fund_cluster_id' => ['nullable', Rule::exists('fund_clusters', 'id')->where('is_active', true)],
            'full_name' => ['required', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(array_values(PayrollEmployeeTypeService::TYPES))],
            'salary_grade' => ['nullable', 'string', 'max:50'],
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'rate_per_day' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'rate_per_hour' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'rate_per_minute' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'tax_status' => ['nullable', 'string', 'max:100'],
            'bir_sworn_status' => ['nullable', 'string', 'max:100'],
            'sss_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'philhealth_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'philhealth_contribution_type' => ['required', Rule::in([
                StatutoryDeductionService::PHILHEALTH_DIRECT,
                StatutoryDeductionService::PHILHEALTH_INDIRECT,
            ])],
            'pagibig_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'nsca_mpc_amount' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'other_deductions_amount' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (! $user->isUniversityWide()) {
            $validated['campus_id'] = $user->campus_id;
        }

        $validated = array_merge($validated, $this->ratesFromMonthlySalary((float) $validated['monthly_salary']));
        $validated['employment_type'] = $employeeTypes->normalize($validated['employment_type']);
        $validated['is_active'] = $request->boolean('is_active');

        $employee->fill($validated);
        $employee->fill($statutoryDeductions->amounts($employee));
        $employee->save();

        $audit->record('employees.updated', $user, $employee, 'Employee payroll record updated.', [
            'campus_id' => $employee->campus_id,
            'employee_no' => $employee->employee_no,
        ]);

        return back()->with('status', 'Employee payroll record updated.');
    }

    public function syncFromHris(Request $request, HrisEmployeeSyncService $sync)
    {
        $user = $request->user();

        abort_unless($user->canManageHris(), 403);

        $result = $sync->sync($request->only(['campus_id', 'emp_status']), $user);

        return back()
            ->with('hris_status', $result['status'])
            ->with('employee_sync', $result);
    }

    private function ratesFromMonthlySalary(float $monthlySalary): array
    {
        $ratePerDay = round($monthlySalary / 22, 2);
        $ratePerHour = round($ratePerDay / 8, 2);

        return [
            'rate_per_day' => $ratePerDay,
            'rate_per_hour' => $ratePerHour,
            'rate_per_minute' => round($ratePerHour / 60, 4),
        ];
    }
}
