{{-- Shared by the standalone create page and the "Generate Payroll" modal on the index. --}}
<div class="form-grid">
    <label class="field"><span><x-icon name="campus" /> Campus</span><select class="select" name="campus_id" data-searchable-select required>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected((string) old('campus_id', $selectedCampusId ?? '') === (string) $campus->id)>{{ $campus->name }}</option>@endforeach</select></label>
    <label class="field"><span><x-icon name="calendar" /> Payroll Period</span><select class="select" name="payroll_period_id" data-searchable-select required>@foreach($periods as $period)<option value="{{ $period->id }}" @selected((string) old('payroll_period_id', '') === (string) $period->id)>{{ $period->name }} ({{ $period->date_from->format('M d') }} - {{ $period->date_to->format('M d, Y') }})</option>@endforeach</select></label>
    <label class="field"><span><x-icon name="employees" /> Employee Group</span><select class="select" name="payroll_employee_type" data-searchable-select required>@foreach($employeeTypes as $type => $label)<option value="{{ $type }}" @selected(old('payroll_employee_type', 'regular') === $type)>{{ $label }}</option>@endforeach</select></label>
</div>
<div class="subsection-title">Payroll Funds</div>
<div class="panel-note">
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px">
        @foreach($fundClusters as $fund)
            <span class="badge gray">{{ $fund->code }}</span>
        @endforeach
    </div>
    <div class="card-kicker">
        One run creates a separate draft for every fund above, matching the tabs of the manual payroll workbook.
        Each employee is placed on the fund of their assigned fund cluster, so nobody appears on two drafts.
        Employees with no fund cluster land on the {{ \App\Services\PayrollFundTypeService::FALLBACK_TYPE }} draft flagged as missing fund source.
        Funds with no employees are skipped.
    </div>
</div>
<div class="subsection-title">Report Signatories</div>
<div class="form-grid">
    @foreach($signatoryRoles as $role => $label)
        <label class="field">
            <span>{{ $label }}</span>
            <select class="select" name="signatories[{{ $role }}]" data-employee-search required>
                <option value="">Select employee</option>
                @foreach($signatoryEmployees as $employee)
                    <option value="{{ $employee->id }}" @selected((string) old('signatories.'.$role, $defaultSignatories[$role] ?? '') === (string) $employee->id)>{{ $employee->full_name }}{{ $employee->designation ? ' - ' . $employee->designation : '' }}</option>
                @endforeach
            </select>
        </label>
    @endforeach
</div>
<label class="field"><span>Generation remarks</span><textarea name="remarks" rows="3" placeholder="Reason, source file, or sync note">{{ old('remarks') }}</textarea></label>
