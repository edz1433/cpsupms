<?php

namespace App\Services;

use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrisTardinessSyncService
{
    private const TIME_KEYS = ['am_in', 'am_out', 'pm_in', 'pm_out', 'ot_in', 'ot_out'];

    private const TIME_ALIASES = [
        'am_in' => ['am_in', 'am_time_in', 'am_timein', 'am in', 'amin', 'morning_in', 'morning_time_in', 'time_in_am', 'in_am', 'first_in', 'first_log', 'time1', 'log1', 'time_in'],
        'am_out' => ['am_out', 'am_time_out', 'am_timeout', 'am out', 'amout', 'morning_out', 'morning_time_out', 'time_out_am', 'out_am', 'lunch_out', 'second_log', 'time2', 'log2'],
        'pm_in' => ['pm_in', 'pm_time_in', 'pm_timein', 'pm in', 'pmin', 'afternoon_in', 'afternoon_time_in', 'time_in_pm', 'in_pm', 'lunch_in', 'third_log', 'time3', 'log3'],
        'pm_out' => ['pm_out', 'pm_time_out', 'pm_timeout', 'pm out', 'pmout', 'afternoon_out', 'afternoon_time_out', 'time_out_pm', 'out_pm', 'last_out', 'fourth_log', 'time4', 'log4', 'time_out'],
        'ot_in' => ['ot_in', 'ot_time_in', 'ot_timein', 'overtime_in', 'overtime_start', 'overtime in', 'overtimein', 'time5', 'log5'],
        'ot_out' => ['ot_out', 'ot_time_out', 'ot_timeout', 'overtime_out', 'overtime_end', 'overtime out', 'overtimeout', 'time6', 'log6'],
    ];

    public function __construct(private HrisAttendanceDatabaseService $hris) {}

    public function syncForPayroll(PayrollPeriod $period, int|string $campusId, Collection $employees, ?User $user = null): array
    {
        $result = $this->fetchForPayroll($period, $campusId, $employees, $user);

        if (($result['status'] ?? null) !== 'connected') {
            $this->clearPreviousSyncFailureFlags($period, $employees);
        }

        return $result;
    }

    public function fetchForPayroll(PayrollPeriod $period, int|string $campusId, Collection $employees, ?User $user = null): array
    {
        $payload = [
            'date_from' => $period->date_from->toDateString(),
            'date_to' => $period->date_to->toDateString(),
            'emp_status' => 'all',
            'campus_id' => (string) $campusId,
            'emp_id' => $employees->pluck('employee_no')->filter()->values()->all() ?: 'all',
        ];

        $result = $this->hris->tardiness($payload, $user);

        if (($result['status'] ?? null) !== 'connected') {
            $message = $result['message'] ?? 'Unable to read attendance from the HRIS database.';

            return [
                'status' => $result['status'] ?? 'unavailable',
                'message' => $message,
                'updated' => 0,
                'flagged' => 0,
                'blocking' => true,
            ];
        }

        $rows = $this->rows($result['data'] ?? []);
        $rowsByEmployeeNo = $rows
            ->mapWithKeys(fn (array $row) => [$this->employeeNumber($row) => $row])
            ->filter(fn ($row, $employeeNo) => $employeeNo !== '');
        $updated = 0;
        $flagged = 0;
        $reviewItemsByEmployeeNo = [];

        foreach ($employees as $employee) {
            $row = $rowsByEmployeeNo->get($employee->employee_no);
            $existing = AttendanceSummary::query()
                ->where('employee_id', $employee->id)
                ->where('payroll_period_id', $period->id)
                ->first();
            $summary = $this->summary($employee, $period, $row, $existing);

            AttendanceSummary::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'payroll_period_id' => $period->id,
                ],
                $this->attendancePayload([
                    'present_days' => $summary['present_days'],
                    'absent_days' => $summary['absent_days'],
                    'late_minutes' => $summary['late_minutes'],
                    'undertime_minutes' => $summary['undertime_minutes'],
                    'missing_log_status' => $summary['missing_log_status'],
                    'review_items' => $summary['review_items'],
                    'last_synced_at' => now(),
                ])
            );

            $updated++;

            if ($summary['missing_log_status'] !== 'No issue') {
                $flagged++;
            }

            $reviewItemsByEmployeeNo[$employee->employee_no] = $summary['review_items'];
        }

        return [
            'status' => 'connected',
            'message' => 'HRIS attendance read directly for payroll generation.',
            'updated' => $updated,
            'flagged' => $flagged,
            'review_items_by_employee_no' => $reviewItemsByEmployeeNo,
        ];
    }

    public function fillMissingReviewTimes(PayrollPeriod $period, int|string $campusId, Collection $lines, ?User $user = null): int
    {
        $lines = $lines
            ->filter(fn ($line) => $this->lineNeedsReviewTimes($line))
            ->values();

        if ($lines->isEmpty()) {
            return 0;
        }

        $employees = Employee::query()
            ->whereIn('employee_no', $lines->pluck('employee_no')->filter()->unique()->values())
            ->get();
        $sync = $this->fetchForPayroll($period, $campusId, $employees, $user);

        if (($sync['status'] ?? null) !== 'connected') {
            return 0;
        }

        $itemsByEmployeeNo = $sync['review_items_by_employee_no'] ?? [];
        $updated = 0;

        foreach ($lines as $line) {
            $freshItems = $itemsByEmployeeNo[$line->employee_no] ?? [];
            $storedItems = $this->reviewItemsForLine($line, $period);
            $mergedItems = $this->mergeReviewItemTimes($storedItems, $freshItems);

            if ($mergedItems === ($line->computed_columns['attendance_review_items'] ?? [])) {
                continue;
            }

            $computedColumns = $line->computed_columns ?? [];
            $computedColumns['attendance_review_items'] = $mergedItems;
            $line->update(['computed_columns' => $computedColumns]);
            $line->setAttribute('computed_columns', $computedColumns);
            $updated++;
        }

        return $updated;
    }

    private function rows(array $data): Collection
    {
        $rows = $data['data'] ?? $data['employees'] ?? $data['rows'] ?? $data['tardiness'] ?? $data;

        if (! is_array($rows)) {
            return collect();
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->values();
    }

    private function summary(Employee $employee, PayrollPeriod $period, ?array $row, ?AttendanceSummary $existing = null): array
    {
        $defaultPresentDays = $existing ? (float) $existing->present_days : $period->date_from->diffInWeekdays($period->date_to) + 1;
        $summary = is_array($row['summary'] ?? null) ? $row['summary'] : [];
        $daily = collect($row['daily'] ?? $row['days'] ?? [])
            ->filter(fn ($day) => is_array($day));
        $dtrItems = $this->dtrItems($daily);
        $reviewItems = collect($dtrItems)
            ->filter(fn (array $item) => ($item['issues'] ?? []) !== [])
            ->values()
            ->all();
        $reasons = collect($reviewItems)
            ->map(function (array $item) {
                return $item['date_label'].': '.$item['summary'];
            })
            ->all();

        $missingLogStatus = $reasons === []
            ? 'No issue'
            : Str::limit('Needs HR Review: '.collect($reasons)->unique()->implode('; '), 240, '...');

        return [
            'present_days' => (float) $this->firstValue([$summary, $row ?? []], ['present_days'], $defaultPresentDays),
            'absent_days' => (float) $this->firstValue([$summary, $row ?? []], ['absent_days'], $existing ? (float) $existing->absent_days : 0),
            'late_minutes' => (int) $this->firstValue([$summary, $row ?? []], ['total_late_minutes', 'late_minutes'], $existing ? (int) $existing->late_minutes : 0),
            'undertime_minutes' => (int) $this->firstValue([$summary, $row ?? []], ['total_undertime_minutes', 'undertime_minutes'], $existing ? (int) $existing->undertime_minutes : 0),
            'missing_log_status' => $missingLogStatus,
            'review_items' => $dtrItems,
        ];
    }

    private function dtrItems(Collection $daily): array
    {
        return $daily
            ->map(fn (array $day) => $this->dtrItem($day))
            ->filter()
            ->sortBy('date')
            ->values()
            ->all();
    }

    private function dtrItem(array $day): ?array
    {
        $date = $this->date($day['date'] ?? $day['attendance_date'] ?? null);

        if (! $date || $date->isWeekend()) {
            return null;
        }

        $issues = [];
        $times = $this->times($day);
        if (filter_var($day['time_in_review'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $issues[] = 'missing time-in';
        }

        if (filter_var($day['time_out_review'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $issues[] = 'missing time-out';
        }

        $issues = collect($issues)->unique()->values()->all();

        if ($issues === []) {
            if (! $this->hasAnyTime($times)) {
                return null;
            }
        }

        return [
            'date' => $date->toDateString(),
            'date_label' => $date->format('M d'),
            'weekday' => $date->format('D'),
            'summary' => implode(', ', $issues),
            'issues' => $issues,
            'times' => $times,
            'late_minutes' => (int) $this->firstValue([$day], ['late_minutes', 'total_late_minutes', 'minutes_late'], 0),
            'undertime_minutes' => (int) $this->firstValue([$day], ['undertime_minutes', 'total_undertime_minutes', 'minutes_undertime'], 0),
        ];
    }

    private function hasAnyTime(array $times): bool
    {
        foreach ($times as $time) {
            if ($time !== null && $time !== '') {
                return true;
            }
        }

        return false;
    }

    private function reviewItemsNeedTimes(array $items): bool
    {
        foreach ($items as $item) {
            if (! is_array($item) || ($item['issues'] ?? []) === []) {
                continue;
            }

            $times = $item['times'] ?? null;
            $normalizedTimes = is_array($times) ? $this->normalizeTimes($times) : [];

            if (! is_array($times) || $times !== $normalizedTimes || ! $this->hasAnyTime($normalizedTimes)) {
                return true;
            }
        }

        return false;
    }

    private function lineNeedsReviewTimes($line): bool
    {
        $items = $line->computed_columns['attendance_review_items'] ?? [];

        if ($this->reviewItemsNeedTimes($items)) {
            return true;
        }

        return $items === []
            && (string) ($line->missing_log_status ?? 'No issue') !== 'No issue'
            && ! Str::contains((string) $line->missing_log_status, ['HRIS returned HTTP', 'HRIS tardiness sync failed', 'HRIS API']);
    }

    private function reviewItemsForLine($line, PayrollPeriod $period): array
    {
        $items = $line->computed_columns['attendance_review_items'] ?? [];

        if (is_array($items) && $items !== []) {
            return $items;
        }

        return $this->fallbackReviewItems((string) ($line->missing_log_status ?? ''), $period);
    }

    private function fallbackReviewItems(string $reason, PayrollPeriod $period): array
    {
        $reason = trim(str_replace('Needs HR Review:', '', $reason));

        if ($reason === '' || $reason === 'No issue') {
            return [];
        }

        return collect(explode(';', $reason))
            ->map(fn (string $issue) => trim($issue))
            ->filter()
            ->map(function (string $issue) use ($period) {
                $dateLabel = 'Summary';
                $summary = $issue;

                if (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2})\s*:\s*(.+)$/', $issue, $matches)) {
                    $dateLabel = $matches[1];
                    $summary = trim($matches[2]);
                } elseif (preg_match('/\(([^)]+)\)\s*$/', $issue, $matches)) {
                    $dateLabel = $matches[1];
                    $summary = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $issue) ?? $issue);
                }

                $date = $this->dateFromLabel($dateLabel, $period);
                $issues = collect(explode(',', $summary))
                    ->map(fn (string $item) => trim($item))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'date' => $date ?: $dateLabel,
                    'date_label' => $dateLabel,
                    'weekday' => $date ? CarbonImmutable::parse($date)->format('D') : '',
                    'summary' => $summary,
                    'issues' => $issues === [] ? [$summary] : $issues,
                    'times' => $this->emptyTimes(),
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                ];
            })
            ->filter(fn (array $item) => ($item['date_label'] ?? '') !== 'Summary')
            ->values()
            ->all();
    }

    private function dateFromLabel(string $dateLabel, PayrollPeriod $period): ?string
    {
        if ($dateLabel === '' || $dateLabel === 'Summary') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($dateLabel.' '.$period->date_from->year);
        } catch (\Throwable) {
            return null;
        }

        if ($date->betweenIncluded($period->date_from, $period->date_to)) {
            return $date->toDateString();
        }

        return null;
    }

    private function emptyTimes(): array
    {
        return collect(self::TIME_KEYS)
            ->mapWithKeys(fn (string $key) => [$key => null])
            ->all();
    }

    private function mergeReviewItemTimes(array $storedItems, array $freshItems): array
    {
        $freshByDate = collect($freshItems)
            ->filter(fn ($item) => is_array($item))
            ->flatMap(function (array $item) {
                return collect([$item['date'] ?? null, $item['date_label'] ?? null])
                    ->filter(fn ($key) => $key !== null && $key !== '')
                    ->mapWithKeys(fn ($key) => [(string) $key => $item])
                    ->all();
            });

        return collect($storedItems)
            ->map(function ($item) use ($freshByDate) {
                if (! is_array($item)) {
                    return $item;
                }

                $times = is_array($item['times'] ?? null) ? $this->normalizeTimes($item['times']) : [];

                $fresh = $freshByDate->get((string) ($item['date'] ?? ''))
                    ?? $freshByDate->get((string) ($item['date_label'] ?? ''));

                if (! is_array($fresh) || ! is_array($fresh['times'] ?? null)) {
                    return $item;
                }

                $freshTimes = $this->normalizeTimes($fresh['times']);

                if ($times === $freshTimes) {
                    return $item;
                }

                $item['times'] = $freshTimes;

                return $item;
            })
            ->all();
    }

    private function times(array $day): array
    {
        $source = $this->normalizedValue($day, ['times']);

        if (! is_array($source)) {
            $source = $this->normalizedValue($day, ['punches']);
        }

        if (is_array($source)) {
            return $this->timesFromSource($source);
        }

        return collect(self::TIME_ALIASES)
            ->mapWithKeys(fn (array $aliases, string $key) => [$key => $this->time($this->timeValue($day, $aliases))])
            ->all();
    }

    private function timesFromSource(array $source): array
    {
        return collect(self::TIME_ALIASES)
            ->mapWithKeys(fn (array $aliases, string $key) => [$key => $this->time($this->normalizedValue($source, $aliases))])
            ->all();
    }

    private function normalizeTimes(array $times): array
    {
        return collect(self::TIME_KEYS)
            ->mapWithKeys(fn (string $key) => [$key => $this->time($this->normalizedValue($times, self::TIME_ALIASES[$key]))])
            ->all();
    }

    private function timeValue(array $day, array $keys): mixed
    {
        $specificKeys = collect($keys)
            ->reject(fn ($key) => in_array($this->normalizeKey((string) $key), ['timein', 'timeout'], true))
            ->values()
            ->all();

        $value = $this->firstValue([$day], $specificKeys, null);

        if ($value !== null && $value !== '') {
            return $value;
        }

        $value = $this->normalizedValue($day, $specificKeys);

        if ($value !== null && $value !== '') {
            return $value;
        }

        foreach (['time', 'dtr', 'attendance', 'logs'] as $nestedKey) {
            $nested = $this->normalizedValue($day, [$nestedKey]);

            if (is_array($nested)) {
                $value = $this->normalizedValue($nested, $keys);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        $value = $this->firstValue([$day], $keys, null);

        if ($value !== null && $value !== '') {
            return $value;
        }

        $value = $this->normalizedValue($day, $keys);

        if ($value !== null && $value !== '') {
            return $value;
        }

        return $this->logTimeValue($day, $keys);
    }

    private function normalizedValue(array $source, array $keys): mixed
    {
        $normalizedKeys = collect($keys)
            ->map(fn ($key) => $this->normalizeKey((string) $key))
            ->all();

        foreach ($source as $key => $value) {
            if (in_array($this->normalizeKey((string) $key), $normalizedKeys, true)) {
                return $value;
            }
        }

        return null;
    }

    private function logTimeValue(array $day, array $keys): mixed
    {
        $logs = $this->normalizedValue($day, ['logs', 'dtr_logs', 'punches', 'time_logs']);

        if (! is_array($logs)) {
            return null;
        }

        $target = collect($keys)->map(fn ($key) => $this->normalizeKey((string) $key))->all();

        foreach ($logs as $log) {
            if (! is_array($log)) {
                continue;
            }

            $label = $this->normalizeKey((string) ($this->normalizedValue($log, ['label', 'type', 'period', 'slot', 'name']) ?? ''));

            if ($label === '' || ! in_array($label, $target, true)) {
                continue;
            }

            $value = $this->normalizedValue($log, ['time', 'value', 'punch', 'log_time']);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
    }

    private function firstValue(array $sources, array $keys, mixed $default): mixed
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (is_array($source) && array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                    return $source[$key];
                }
            }
        }

        return $default;
    }

    private function employeeNumber(array $row): string
    {
        $employee = is_array($row['employee'] ?? null) ? $row['employee'] : [];

        return trim((string) ($row['emp_ID']
            ?? $row['emp_id']
            ?? $row['employee_no']
            ?? $row['employee_number']
            ?? $employee['emp_ID']
            ?? $employee['emp_id']
            ?? $employee['employee_no']
            ?? ''));
    }

    private function displayDate(mixed $date): string
    {
        $parsed = $this->date($date);

        if (! $parsed) {
            return '';
        }

        return '('.$parsed->format('M d').')';
    }

    private function date(mixed $date): ?CarbonImmutable
    {
        if (! $date) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (\Throwable) {
            return null;
        }
    }

    private function time(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        $time = trim((string) $time);

        if (preg_match('/^\d{1,2}:\d{1,2}(?::\d{1,2})?$/', $time)) {
            [$hour, $minute] = explode(':', $time);

            return str_pad((string) ((int) $hour % 24), 2, '0', STR_PAD_LEFT)
                .':'.str_pad((string) min(59, (int) $minute), 2, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^\d{3,4}$/', $time)) {
            $time = str_pad($time, 4, '0', STR_PAD_LEFT);

            return substr($time, 0, 2).':'.substr($time, 2, 2);
        }

        try {
            return CarbonImmutable::parse($time)->format('H:i');
        } catch (\Throwable) {
            return $time;
        }
    }

    private function clearPreviousSyncFailureFlags(PayrollPeriod $period, Collection $employees): void
    {
        $employeeIds = $employees->pluck('id')->filter()->values();

        if ($employeeIds->isEmpty()) {
            return;
        }

        AttendanceSummary::query()
            ->where('payroll_period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->where(function ($query) {
                $query->where('missing_log_status', 'like', 'Needs HR Review: HRIS returned HTTP%')
                    ->orWhere('missing_log_status', 'like', 'Needs HR Review: HRIS tardiness sync failed%')
                    ->orWhere('missing_log_status', 'like', 'Needs HR Review: HRIS API%');
            })
            ->update($this->attendancePayload([
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'missing_log_status' => 'No issue',
                'review_items' => null,
                'last_synced_at' => now(),
            ]));
    }

    private function attendancePayload(array $payload): array
    {
        if (! $this->hasReviewItemsColumn()) {
            unset($payload['review_items']);
        }

        return $payload;
    }

    private function hasReviewItemsColumn(): bool
    {
        static $hasColumn = null;

        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('attendance_summaries', 'review_items');
        }

        return $hasColumn;
    }
}
