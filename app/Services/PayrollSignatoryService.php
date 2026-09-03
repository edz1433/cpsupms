<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class PayrollSignatoryService
{
    public const ROLES = [
        'prepared_by' => 'Prepared by',
        'certified_correct_by' => 'Certified Correct',
        'approved_by' => 'Approved for Payment',
        'certified_payment_by' => 'Certified Payment',
    ];

    public function employeeOptions(User $user): Collection
    {
        return Employee::query()
            ->visibleTo($user)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'employee_no', 'full_name', 'designation', 'campus_id']);
    }

    public function snapshot(array $signatories): array
    {
        $ids = collect(self::ROLES)
            ->keys()
            ->mapWithKeys(fn ($role) => [$role => $signatories[$role] ?? null])
            ->filter()
            ->all();
        $employees = Employee::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect(self::ROLES)
            ->mapWithKeys(function (string $label, string $role) use ($ids, $employees) {
                $employee = ! empty($ids[$role]) ? $employees->get((int) $ids[$role]) : null;

                return [$role => [
                    'label' => $label,
                    'employee_id' => $employee?->id,
                    'name' => $employee?->full_name,
                    'designation' => $employee?->designation,
                ]];
            })
            ->all();
    }

    public function forBatch(PayrollBatch $batch): array
    {
        $stored = $batch->snapshot['signatories'] ?? [];

        return collect(self::ROLES)
            ->mapWithKeys(fn (string $label, string $role) => [$role => [
                'label' => $stored[$role]['label'] ?? $label,
                'employee_id' => $stored[$role]['employee_id'] ?? null,
                'name' => $stored[$role]['name'] ?? null,
                'designation' => $stored[$role]['designation'] ?? null,
            ]])
            ->all();
    }

    public function defaultsForCreate(User $user): array
    {
        if ($user->role?->slug !== 'campus-payroll-administrator') {
            return [];
        }

        $options = $this->employeeOptions($user)->keyBy('id');
        $batch = PayrollBatch::query()
            ->visibleTo($user)
            ->whereNotNull('snapshot')
            ->latest()
            ->get()
            ->first(fn (PayrollBatch $batch) => ! empty($batch->snapshot['signatories']));

        if (! $batch) {
            return [];
        }

        return collect($this->forBatch($batch))
            ->mapWithKeys(function (array $signatory, string $role) use ($options) {
                $employeeId = $signatory['employee_id'] ?? null;

                return [$role => $employeeId && $options->has((int) $employeeId) ? (int) $employeeId : null];
            })
            ->filter()
            ->all();
    }
}
