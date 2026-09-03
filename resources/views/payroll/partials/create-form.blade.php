{{-- Shared by the standalone create page and the "Generate Payroll" modal on the index. --}}
<div class="generate-grid generate-grid-3">
    <label class="field">
        <span><x-icon name="campus" /> Campus</span>
        <select class="select" name="campus_id" data-searchable-select required>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected((string) old('campus_id', $selectedCampusId ?? '') === (string) $campus->id)>{{ $campus->name }}</option>@endforeach</select>
    </label>

    <label class="field">
        <span><x-icon name="calendar" /> Payroll Period</span>
        <select class="select" name="payroll_period_id" data-searchable-select required>@foreach($periods as $period)<option value="{{ $period->id }}" @selected((string) old('payroll_period_id', '') === (string) $period->id)>{{ $period->name }} ({{ $period->date_from->format('M d') }} - {{ $period->date_to->format('M d, Y') }})</option>@endforeach</select>
    </label>

    <label class="field">
        <span><x-icon name="employees" /> Employee Group</span>
        <select class="select" name="payroll_employee_type" data-searchable-select required>@foreach($employeeTypes as $type => $label)<option value="{{ $type }}" @selected(old('payroll_employee_type', 'regular') === $type)>{{ $label }}</option>@endforeach</select>
    </label>
</div>

<div class="section-rule"><span>Report Signatories</span></div>

<div class="generate-grid">
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

    <label class="field generate-span">
        <span>Generation remarks <em class="field-hint">optional</em></span>
        <textarea name="remarks" rows="2" placeholder="Reason, source file, or sync note">{{ old('remarks') }}</textarea>
    </label>
</div>
