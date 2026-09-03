<x-layouts.app page-title="Employees" page-subtitle="Payroll employee records only; no employee login accounts">
    @php
        $user = auth()->user();
        $canEditEmployees = $user->canManagePayroll();
        $isUniversityWide = $user->isUniversityWide();
        $statusFilter = $filters['status'] ?? 'active';
        $employeeTypeService = app(\App\Services\PayrollEmployeeTypeService::class);
    @endphp
    <div class="card">
        <div class="card-header">
            <div><h2>Employees</h2><div class="card-kicker">Campus-scoped payroll records and salary references</div></div>
            @if($user->canManageHris())
                <form class="hris-sync-form" method="POST" action="{{ route('employees.sync-hris') }}">
                    @csrf
                    <button class="gold-btn" type="submit">
                        <span class="button-spinner" aria-hidden="true"></span>
                        <x-icon class="sync-idle icon" name="download" />
                        <span class="sync-idle">Sync Employees from HRIS</span>
                        <span class="sync-loading">Syncing employees...</span>
                    </button>
                </form>
            @endif
        </div>
        <div class="alert success sync-loading-note">
            <x-icon name="download" />
            <span>Importing employee records from HRIS. Please wait and keep this tab open.</span>
        </div>
        @if(session('employee_sync'))
            @php($sync = session('employee_sync'))
            <div class="alert {{ $sync['status'] === 'connected' ? 'success' : 'danger' }}">
                <x-icon :name="$sync['status'] === 'connected' ? 'check' : 'alert'" />
                <span>
                    Employee sync {{ $sync['status'] }}:
                    {{ number_format($sync['imported'] ?? 0) }} imported,
                    {{ number_format($sync['updated'] ?? 0) }} updated,
                    {{ number_format($sync['skipped'] ?? 0) }} skipped.
                    @if(!empty($sync['message']))
                        {{ $sync['message'] }}
                    @endif
                </span>
            </div>
        @endif
        <form class="employee-filterbar {{ $isUniversityWide ? '' : 'campus-scoped' }}" method="GET" action="{{ route('employees.index') }}">
            <label class="filter-search">
                <x-icon name="search" />
                <input name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Search employee, ID, office, designation, fund">
            </label>

            @if($isUniversityWide)
                <label class="filter-field">
                    <span>Campus</span>
                    <select name="campus_id">
                        <option value="">All campuses</option>
                        @foreach($campuses as $campus)
                            <option value="{{ $campus->id }}" @selected((string) ($filters['campus_id'] ?? '') === (string) $campus->id)>{{ $campus->code }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="filter-field">
                <span>Fund</span>
                <select name="fund_cluster_id">
                    <option value="">All funds</option>
                    @foreach($fundClusters as $fund)
                        <option value="{{ $fund->id }}" @selected((string) ($filters['fund_cluster_id'] ?? '') === (string) $fund->id)>{{ $fund->code }} - {{ $fund->fund_source_name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="filter-field">
                <span>Type</span>
                <select name="employment_type">
                    <option value="">All types</option>
                    @foreach($employmentTypes as $type => $label)
                        <option value="{{ $type }}" @selected(($filters['employment_type'] ?? '') === $type)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="filter-field compact">
                <span>Status</span>
                <select name="status">
                    <option value="active" @selected($statusFilter === 'active')>Active</option>
                    <option value="inactive" @selected($statusFilter === 'inactive')>Inactive</option>
                    <option value="all" @selected($statusFilter === 'all')>All</option>
                </select>
            </label>

            <div class="filter-actions">
                <button class="primary-btn" type="submit"><x-icon name="filter" /> Apply</button>
                <a class="ghost-btn icon-btn" href="{{ route('employees.index') }}" title="Clear filters"><x-icon name="x" /></a>
            </div>
        </form>
        <div class="table-meta">
            <span>{{ number_format($employees->total()) }} shown</span>
            <span>{{ number_format($totalEmployees) }} total payroll records</span>
            @if($employees->total() > 0)
                <span>Rows {{ number_format($employees->firstItem()) }}-{{ number_format($employees->lastItem()) }}</span>
            @endif
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Name</th><th>Campus</th><th>Office</th><th>Designation</th><th>Type</th><th>Fund</th><th class="num">Monthly</th><th class="num">Tax</th>@if($canEditEmployees)<th class="num">Action</th>@endif</tr></thead><tbody>
        @forelse($employees as $employee)
            <tr>
                <td>{{ $employee->employee_no }}</td>
                <td><strong>{{ $employee->full_name }}</strong></td>
                <td>{{ $employee->campus?->code }}</td>
                <td>{{ $employee->office?->office_name }}</td>
                <td>{{ $employee->designation }}</td>
                <td><span class="badge gray">{{ $employeeTypeService->normalize($employee->employment_type) }}</span></td>
                <td>{{ $employee->fundCluster?->fund_source_name }}</td>
                <td class="num">{{ number_format($employee->monthly_salary, 2) }}</td>
                <td class="num">{{ number_format($employee->tax_rate * 100, 0) }}%</td>
                @if($canEditEmployees)
                    <td class="num">
                        <button
                            class="ghost-btn icon-btn employee-edit-button"
                            type="button"
                            title="Edit employee"
                            data-update-url="{{ route('employees.update', $employee) }}"
                            data-employee-no="{{ $employee->employee_no }}"
                            data-full-name="{{ $employee->full_name }}"
                            data-campus-id="{{ $employee->campus_id }}"
                            data-fund-cluster-id="{{ $employee->fund_cluster_id }}"
                            data-office-id="{{ $employee->office_id }}"
                            data-designation="{{ $employee->designation }}"
                            data-employment-type="{{ $employeeTypeService->normalize($employee->employment_type) }}"
                            data-salary-grade="{{ $employee->salary_grade }}"
                            data-monthly-salary="{{ $employee->monthly_salary }}"
                            data-rate-per-day="{{ $employee->rate_per_day }}"
                            data-rate-per-hour="{{ $employee->rate_per_hour }}"
                            data-rate-per-minute="{{ $employee->rate_per_minute }}"
                            data-tax-rate="{{ $employee->tax_rate }}"
                            data-tax-status="{{ $employee->tax_status }}"
                            data-bir-sworn-status="{{ $employee->bir_sworn_status }}"
                            data-sss-amount="{{ $employee->sss_amount }}"
                            data-philhealth-amount="{{ $employee->philhealth_amount }}"
                            data-philhealth-contribution-type="{{ $employee->philhealth_contribution_type ?? 'direct' }}"
                            data-pagibig-amount="{{ $employee->pagibig_amount }}"
                            data-nsca-mpc-amount="{{ $employee->nsca_mpc_amount }}"
                            data-other-deductions-amount="{{ $employee->other_deductions_amount }}"
                            data-is-active="{{ $employee->is_active ? '1' : '0' }}"
                        >
                            <x-icon name="edit" />
                        </button>
                    </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $canEditEmployees ? 10 : 9 }}"><div class="table-empty">No employees found.</div></td></tr>
        @endforelse
        </tbody></table></div>
        <div style="margin-top:16px">{{ $employees->links() }}</div>
    </div>
    @if($canEditEmployees)
        <dialog class="modal" id="employee-edit-modal">
            <form class="modal-panel" method="POST" id="employee-edit-form">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <div>
                        <h2>Edit Employee</h2>
                        <div class="card-kicker">Payroll record details</div>
                    </div>
                    <button class="ghost-btn icon-btn" type="button" data-close-employee-modal title="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="modal-grid">
                        <label class="field"><span>Emp ID</span><input class="input" id="edit_employee_no" type="text" disabled></label>
                        <label class="field"><span>Name</span><input class="input" id="edit_full_name" name="full_name" type="text" required></label>

                        @if($isUniversityWide)
                            <label class="field"><span>Campus</span><select class="select" id="edit_campus_id" name="campus_id" required>@foreach($campuses as $campus)<option value="{{ $campus->id }}">{{ $campus->name }}</option>@endforeach</select></label>
                        @else
                            <input id="edit_campus_id_hidden" name="campus_id" type="hidden" value="{{ $user->campus_id }}">
                            <label class="field"><span>Campus</span><select class="select" id="edit_campus_id" disabled>@foreach($campuses as $campus)<option value="{{ $campus->id }}">{{ $campus->name }}</option>@endforeach</select></label>
                        @endif

                        <label class="field"><span>Fund Cluster</span><select class="select" id="edit_fund_cluster_id" name="fund_cluster_id"><option value="">Unassigned</option>@foreach($fundClusters as $fund)<option value="{{ $fund->id }}">{{ $fund->code }} - {{ $fund->fund_source_name }}</option>@endforeach</select></label>
                        <label class="field"><span>Office</span><select class="select" id="edit_office_id" name="office_id"><option value="">Unassigned</option>@foreach($offices as $office)<option value="{{ $office->id }}">{{ $office->office_name }}</option>@endforeach</select></label>
                        <label class="field"><span>Designation</span><input class="input" id="edit_designation" name="designation" type="text"></label>
                        <label class="field"><span>Employee Status</span><select class="select" id="edit_employment_type" name="employment_type" required>@foreach($employmentTypes as $type => $label)<option value="{{ $label }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="field"><span>Salary Grade</span><input class="input" id="edit_salary_grade" name="salary_grade" type="text"></label>
                        <label class="field"><span>Monthly Salary</span><input class="input" id="edit_monthly_salary" name="monthly_salary" type="number" min="0" step="0.01" required></label>
                        <label class="field"><span>Rate Per Day</span><input class="input" id="edit_rate_per_day" name="rate_per_day" type="number" min="0" step="0.01" readonly required></label>
                        <label class="field"><span>Rate Per Hour</span><input class="input" id="edit_rate_per_hour" name="rate_per_hour" type="number" min="0" step="0.01" readonly required></label>
                        <label class="field"><span>Rate Per Minute</span><input class="input" id="edit_rate_per_minute" name="rate_per_minute" type="number" min="0" step="0.0001" readonly required></label>
                        <label class="field"><span>Tax Rate</span><input class="input" id="edit_tax_rate" name="tax_rate" type="number" min="0" max="1" step="0.0001" required></label>
                        <label class="field"><span>Tax Status</span><input class="input" id="edit_tax_status" name="tax_status" type="text"></label>
                        <label class="field"><span>BIR Sworn Status</span><input class="input" id="edit_bir_sworn_status" name="bir_sworn_status" type="text"></label>
                        <label class="field"><span>SSS</span><input class="input" id="edit_sss_amount" name="sss_amount" type="number" min="0" step="0.01" readonly required></label>
                        <label class="field"><span>PhilHealth Type</span><select class="select" id="edit_philhealth_contribution_type" name="philhealth_contribution_type" required><option value="direct">Direct</option><option value="indirect">Indirect</option></select></label>
                        <label class="field"><span>PhilHealth</span><input class="input" id="edit_philhealth_amount" name="philhealth_amount" type="number" min="0" step="0.01" readonly required></label>
                        <label class="field"><span>Pag-IBIG</span><input class="input" id="edit_pagibig_amount" name="pagibig_amount" type="number" min="0" step="0.01" readonly required></label>
                        <label class="field"><span>NSCA MPC</span><input class="input" id="edit_nsca_mpc_amount" name="nsca_mpc_amount" type="number" min="0" step="0.01" required></label>
                        <label class="field"><span>Other Deductions</span><input class="input" id="edit_other_deductions_amount" name="other_deductions_amount" type="number" min="0" step="0.01" required></label>
                        <label class="field checkline modal-check"><input id="edit_is_active" name="is_active" type="checkbox" value="1"><span>Active payroll record</span></label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="ghost-btn" type="button" data-close-employee-modal>Cancel</button>
                    <button class="primary-btn" type="submit"><x-icon name="check" /> Save Changes</button>
                </div>
            </form>
        </dialog>
    @endif
    <script>
        document.querySelectorAll('.hris-sync-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.classList.add('is-loading');
                document.querySelectorAll('.sync-loading-note').forEach(function (note) {
                    note.classList.add('is-visible');
                });
                form.querySelectorAll('button').forEach(function (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                });
            });
        });

        const employeeModal = document.getElementById('employee-edit-modal');
        const employeeForm = document.getElementById('employee-edit-form');
        const employeeFields = [
            'employee_no',
            'full_name',
            'campus_id',
            'fund_cluster_id',
            'office_id',
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
            'philhealth_contribution_type',
            'philhealth_amount',
            'pagibig_amount',
            'nsca_mpc_amount',
            'other_deductions_amount',
        ];
        const salaryInput = document.getElementById('edit_monthly_salary');
        const ratePerDayInput = document.getElementById('edit_rate_per_day');
        const ratePerHourInput = document.getElementById('edit_rate_per_hour');
        const ratePerMinuteInput = document.getElementById('edit_rate_per_minute');
        const sssInput = document.getElementById('edit_sss_amount');
        const philhealthTypeInput = document.getElementById('edit_philhealth_contribution_type');
        const philhealthInput = document.getElementById('edit_philhealth_amount');
        const pagibigInput = document.getElementById('edit_pagibig_amount');

        function updateAutomatedRates() {
            const monthlySalary = parseFloat(salaryInput?.value || '0') || 0;
            const ratePerDay = monthlySalary / 22;
            const ratePerHour = ratePerDay / 8;
            const ratePerMinute = ratePerHour / 60;

            if (ratePerDayInput) {
                ratePerDayInput.value = ratePerDay.toFixed(2);
            }

            if (ratePerHourInput) {
                ratePerHourInput.value = ratePerHour.toFixed(2);
            }

            if (ratePerMinuteInput) {
                ratePerMinuteInput.value = ratePerMinute.toFixed(4);
            }
        }

        function updateAutomatedStatutoryDeductions() {
            const monthlySalary = parseFloat(salaryInput?.value || '0') || 0;
            const philhealthType = philhealthTypeInput?.value || 'direct';
            const sssBase = Math.min(Math.max(monthlySalary, 5000), 35000);
            const philhealthBase = Math.min(Math.max(monthlySalary, 10000), 100000);
            const pagibigRate = monthlySalary <= 1500 ? 0.01 : 0.02;

            if (sssInput) {
                sssInput.value = monthlySalary > 0 ? (sssBase * 0.05).toFixed(2) : '0.00';
            }

            if (philhealthInput) {
                philhealthInput.value = monthlySalary > 0 && philhealthType === 'direct' ? (philhealthBase * 0.05).toFixed(2) : '0.00';
            }

            if (pagibigInput) {
                pagibigInput.value = monthlySalary > 0 ? Math.min(monthlySalary * pagibigRate, 200).toFixed(2) : '0.00';
            }
        }

        function updateEmployeeAutomation() {
            updateAutomatedRates();
            updateAutomatedStatutoryDeductions();
        }

        document.querySelectorAll('.employee-edit-button').forEach(function (button) {
            button.addEventListener('click', function () {
                employeeForm.setAttribute('action', button.dataset.updateUrl);

                employeeFields.forEach(function (field) {
                    const input = document.getElementById('edit_' + field);

                    if (input) {
                        const value = button.getAttribute('data-' + field.replace(/_/g, '-')) || '';

                        if (input.tomselect) {
                            input.tomselect.setValue(value, true);
                        } else {
                            input.value = value;
                        }
                    }
                });

                const hiddenCampus = document.getElementById('edit_campus_id_hidden');

                if (hiddenCampus) {
                    hiddenCampus.value = button.dataset.campusId || hiddenCampus.value;
                }

                document.getElementById('edit_is_active').checked = button.dataset.isActive === '1';
                updateEmployeeAutomation();
                employeeModal.showModal();
            });
        });

        if (salaryInput) {
            salaryInput.addEventListener('input', updateEmployeeAutomation);
        }

        if (philhealthTypeInput) {
            philhealthTypeInput.addEventListener('change', updateAutomatedStatutoryDeductions);
        }

        document.querySelectorAll('[data-close-employee-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                employeeModal.close();
            });
        });

        if (employeeModal) {
            employeeModal.addEventListener('click', function (event) {
                if (event.target === employeeModal) {
                    employeeModal.close();
                }
            });
        }
    </script>
</x-layouts.app>
