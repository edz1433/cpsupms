<?php

namespace App\Http\Requests;

use App\Services\PayrollEmployeeTypeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollBatchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // One run now covers every payroll fund, so the fund and its template are
        // resolved per batch during generation instead of being picked in the form.
        $this->merge([
            'payroll_employee_type' => $this->input('payroll_employee_type') ?: PayrollEmployeeTypeService::REGULAR,
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->canManagePayroll() ?? false;
    }

    public function rules(): array
    {
        $user = $this->user();

        return [
            'campus_id' => [
                'required',
                'exists:campuses,id',
                Rule::when(! $user->isUniversityWide(), Rule::in([$user->campus_id])),
            ],
            'payroll_period_id' => ['required', 'exists:payroll_periods,id'],
            'payroll_employee_type' => ['required', Rule::in(array_keys(PayrollEmployeeTypeService::TYPES))],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'signatories' => ['required', 'array'],
            'signatories.prepared_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
            'signatories.certified_correct_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
            'signatories.approved_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
            'signatories.certified_payment_by' => ['required', Rule::exists('employees', 'id')->where('is_active', true)],
        ];
    }
}
