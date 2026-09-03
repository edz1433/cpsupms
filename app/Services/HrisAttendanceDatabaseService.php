<?php

namespace App\Services;

use App\Models\HrisSyncLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HrisAttendanceDatabaseService
{
    private const DEFAULT_SCHEDULE = [
        'morning_in' => '08:00',
        'morning_out' => '12:00',
        'afternoon_in' => '13:00',
        'afternoon_out' => '17:00',
    ];

    private const SCHEDULE_COLUMNS = [
        1 => ['morn_mon', 'aft_mon'],
        2 => ['morn_tue', 'aft_tue'],
        3 => ['morn_wed', 'aft_wed'],
        4 => ['morn_thu', 'aft_thu'],
        5 => ['morn_fri', 'aft_fri'],
    ];

    /**
     * Read payroll attendance directly from the HRIS database.
     */
    public function tardiness(array $filters, ?User $user = null): array
    {
        $started = microtime(true);

        try {
            $dateFrom = CarbonImmutable::parse($filters['date_from'])->startOfDay();
            $dateTo = CarbonImmutable::parse($filters['date_to'])->startOfDay();

            if ($dateFrom->greaterThan($dateTo)) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }

            $employeeIds = $this->employeeIds($filters['emp_id'] ?? []);
            $employeeQuery = DB::connection('hris')
                ->table('employees')
                ->select('emp_ID')
                ->where('stat_1', 1)
                ->whereNotNull('emp_ID')
                ->where('emp_ID', '<>', '');

            if (filled($filters['campus_id'] ?? null)) {
                $employeeQuery->where('camp_id', $filters['campus_id']);
            }

            if (filled($filters['emp_status'] ?? null) && $filters['emp_status'] !== 'all') {
                $employeeQuery->where('emp_status', $filters['emp_status']);
            }

            if ($employeeIds !== []) {
                $employeeQuery->whereIn('emp_ID', $employeeIds);
            }

            $eligibleEmployeeIds = $employeeQuery
                ->pluck('emp_ID')
                ->map(fn ($employeeId) => trim((string) $employeeId))
                ->filter()
                ->unique()
                ->values();

            if ($eligibleEmployeeIds->isEmpty()) {
                $this->log($user, 'tardiness', 'connected', $started, null, $filters, 0);

                return ['status' => 'connected', 'data' => ['data' => []]];
            }

            $dtrs = DB::connection('hris')
                ->table('dtrs')
                ->select(['emp_ID', 'date', 'time_in', 'time_out', 'time_over'])
                ->whereIn('emp_ID', $eligibleEmployeeIds)
                ->whereBetween('date', [$dateFrom->toDateString(), $dateTo->toDateString()])
                ->orderBy('emp_ID')
                ->orderBy('date')
                ->get()
                ->groupBy(fn (object $row) => trim((string) $row->emp_ID));

            $officialTimes = DB::connection('hris')
                ->table('official_times')
                ->whereIn('empid', $eligibleEmployeeIds)
                ->get()
                ->keyBy(fn (object $row) => trim((string) $row->empid));

            $rows = $eligibleEmployeeIds
                ->map(fn (string $employeeId) => $this->employeeAttendance(
                    $employeeId,
                    $dtrs->get($employeeId, collect()),
                    $officialTimes->get($employeeId),
                ))
                ->values()
                ->all();

            $this->log($user, 'tardiness', 'connected', $started, null, $filters, count($rows));

            return ['status' => 'connected', 'data' => ['data' => $rows]];
        } catch (\Throwable $exception) {
            $this->log($user, 'tardiness', 'unavailable', $started, $exception->getMessage(), $filters);

            return [
                'status' => 'unavailable',
                'message' => 'Unable to read attendance from the HRIS database.',
            ];
        }
    }

    private function employeeIds(mixed $employeeIds): array
    {
        if ($employeeIds === 'all' || $employeeIds === null || $employeeIds === '') {
            return [];
        }

        return collect(is_array($employeeIds) ? $employeeIds : [$employeeIds])
            ->map(fn ($employeeId) => trim((string) $employeeId))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function employeeAttendance(string $employeeId, Collection $records, ?object $officialTime): array
    {
        $summary = [
            'total_late_minutes' => 0,
            'total_undertime_minutes' => 0,
            'review_days' => 0,
        ];

        $daily = $records
            ->groupBy(fn (object $record) => CarbonImmutable::parse($record->date)->toDateString())
            ->map(function (Collection $dateRecords, string $date) use ($officialTime, &$summary) {
                $day = $this->attendanceDay($date, $dateRecords, $officialTime);
                $summary['total_late_minutes'] += $day['late_minutes'];
                $summary['total_undertime_minutes'] += $day['undertime_minutes'];
                $summary['review_days'] += ($day['time_in_review'] || $day['time_out_review']) ? 1 : 0;

                return $day;
            })
            ->sortBy('date')
            ->values()
            ->all();

        return [
            'emp_ID' => $employeeId,
            'summary' => $summary,
            'daily' => $daily,
        ];
    }

    private function attendanceDay(string $date, Collection $records, ?object $officialTime): array
    {
        $schedule = $this->scheduleForDate($officialTime, CarbonImmutable::parse($date));
        $timeIns = $this->times($records->pluck('time_in'))
            ->filter(fn (string $time) => $this->minutes($time) <= $this->minutes($schedule['afternoon_in']) + 30)
            ->values();
        $timeOuts = $this->times($records->pluck('time_out'))
            ->filter(fn (string $time) => $this->minutes($time) >= $this->minutes($schedule['morning_out']) - 60)
            ->values();
        $overtime = $this->times($records->pluck('time_over'))->values();
        $completeTimeIns = $timeIns->count() >= 2;
        $completeTimeOuts = $timeOuts->count() >= 2;
        $amIn = $timeIns->first();
        $amOut = $timeOuts->first();
        $pmIn = $completeTimeIns ? $timeIns->last() : null;
        $pmOut = $completeTimeOuts ? $timeOuts->last() : null;

        $lateMinutes = $completeTimeIns
            ? $this->minutesAfter($amIn, $schedule['morning_in']) + $this->minutesAfter($pmIn, $schedule['afternoon_in'])
            : 0;
        $undertimeMinutes = $completeTimeOuts
            ? $this->minutesBefore($amOut, $schedule['morning_out']) + $this->minutesBefore($pmOut, $schedule['afternoon_out'])
            : 0;

        return [
            'date' => $date,
            'time_in_review' => ! $completeTimeIns,
            'time_out_review' => ! $completeTimeOuts,
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'times' => [
                'am_in' => $amIn,
                'am_out' => $amOut,
                'pm_in' => $pmIn,
                'pm_out' => $pmOut,
                'ot_in' => $overtime->first(),
                'ot_out' => $overtime->count() >= 2 ? $overtime->last() : null,
            ],
        ];
    }

    private function scheduleForDate(?object $officialTime, CarbonImmutable $date): array
    {
        $columns = self::SCHEDULE_COLUMNS[$date->dayOfWeekIso] ?? null;

        if (! $officialTime || ! $columns) {
            return self::DEFAULT_SCHEDULE;
        }

        [$morningColumn, $afternoonColumn] = $columns;
        [$morningIn, $morningOut] = $this->scheduleRange(
            $officialTime->{$morningColumn} ?? null,
            self::DEFAULT_SCHEDULE['morning_in'],
            self::DEFAULT_SCHEDULE['morning_out'],
        );
        [$afternoonIn, $afternoonOut] = $this->scheduleRange(
            $officialTime->{$afternoonColumn} ?? null,
            self::DEFAULT_SCHEDULE['afternoon_in'],
            self::DEFAULT_SCHEDULE['afternoon_out'],
        );

        return [
            'morning_in' => $morningIn,
            'morning_out' => $morningOut,
            'afternoon_in' => $afternoonIn,
            'afternoon_out' => $afternoonOut,
        ];
    }

    private function scheduleRange(mixed $range, string $fallbackStart, string $fallbackEnd): array
    {
        $parts = is_string($range) ? array_map('trim', explode('-', $range)) : [];

        return [
            $this->clock($parts[0] ?? null) ?? $fallbackStart,
            $this->clock($parts[1] ?? null) ?? $fallbackEnd,
        ];
    }

    private function times(Collection $values): Collection
    {
        return $values
            ->flatMap(fn ($value) => filled($value) ? explode(',', (string) $value) : [])
            ->map(fn ($time) => $this->clock($time))
            ->filter()
            ->unique()
            ->sortBy(fn (string $time) => $this->minutes($time))
            ->values();
    }

    private function clock(mixed $time): ?string
    {
        if (! filled($time)) {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim((string) $time))->format('H:i');
        } catch (\Throwable) {
            if (preg_match('/\b(\d{1,2}):(\d{2})\b/', (string) $time, $matches)) {
                return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
            }

            return null;
        }
    }

    private function minutes(?string $time): int
    {
        if (! $time) {
            return 0;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    private function minutesAfter(?string $actual, string $expected): int
    {
        return max(0, $this->minutes($actual) - $this->minutes($expected));
    }

    private function minutesBefore(?string $actual, string $expected): int
    {
        return max(0, $this->minutes($expected) - $this->minutes($actual));
    }

    private function log(
        ?User $user,
        string $type,
        string $status,
        float $started,
        ?string $error,
        array $filters,
        ?int $recordsRead = null,
    ): void {
        $payload = collect($filters)->except(['password', 'token', 'api_key'])->all();

        if (is_array($payload['emp_id'] ?? null)) {
            $payload['employee_count'] = count($payload['emp_id']);
            unset($payload['emp_id']);
        }

        if ($recordsRead !== null) {
            $payload['records_read'] = $recordsRead;
        }

        HrisSyncLog::create([
            'user_id' => $user?->id,
            'request_type' => $type,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error_message' => $error ? str($error)->limit(240)->toString() : null,
            'payload_summary' => $payload,
        ]);
    }
}
