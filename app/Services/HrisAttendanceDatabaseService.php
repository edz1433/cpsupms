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
                ->select(['id', 'emp_ID', 'date', 'time_in', 'time_out', 'time_over'])
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
            'total_deductible_minutes' => 0,
            'review_days' => 0,
        ];

        $daily = $records
            ->groupBy(fn (object $record) => CarbonImmutable::parse($record->date)->toDateString())
            ->map(function (Collection $dateRecords, string $date) use ($officialTime, &$summary) {
                $day = $this->attendanceDay($date, $dateRecords, $officialTime);
                $summary['total_late_minutes'] += $day['late_minutes'];
                $summary['total_undertime_minutes'] += $day['undertime_minutes'];
                $summary['total_deductible_minutes'] += $day['deductible_minutes'];
                $summary['review_days'] += $day['review_required'] ? 1 : 0;

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

        // HRIS has no unique key on (emp_ID, date). Merging duplicate rows would let a
        // stray punch change the arranged day, so the newest row wins and the day is
        // flagged for a person to confirm rather than silently combined.
        $ordered = $records->sortBy(fn (object $record) => (int) ($record->id ?? 0))->values();
        $record = $ordered->last();
        $duplicated = $ordered->count() > 1;

        $timeIns = $this->times(collect([$record->time_in ?? null]));
        $timeOuts = $this->times(collect([$record->time_out ?? null]));
        $overtime = $this->times(collect([$record->time_over ?? null]));

        $usableIns = $timeIns
            ->filter(fn (string $time) => $this->minutes($time) <= $this->minutes($schedule['afternoon_in']) + 30)
            ->values();
        $usableOuts = $timeOuts
            ->filter(fn (string $time) => $this->minutes($time) >= $this->minutes($schedule['morning_out']) - 60)
            ->values();

        // The two sides are judged independently: an incomplete IN side must not erase
        // valid undertime, and an incomplete OUT side must not erase valid late.
        $completeIns = $usableIns->count() >= 2;
        $completeOuts = $usableOuts->count() >= 2;

        $amIn = $usableIns->first();
        $pmIn = $completeIns ? $usableIns->last() : null;
        $amOut = $usableOuts->first();
        $pmOut = $completeOuts ? $usableOuts->last() : null;

        $morningLate = $completeIns ? $this->minutesAfter($amIn, $schedule['morning_in']) : 0;
        $afternoonLate = $completeIns ? $this->minutesAfter($pmIn, $schedule['afternoon_in']) : 0;
        $morningUndertime = $completeOuts ? $this->minutesBefore($amOut, $schedule['morning_out']) : 0;
        $afternoonUndertime = $completeOuts ? $this->minutesBefore($pmOut, $schedule['afternoon_out']) : 0;

        $lateMinutes = $morningLate + $afternoonLate;
        $undertimeMinutes = $morningUndertime + $afternoonUndertime;

        $reviewReasons = [];

        if (! $completeIns) {
            $reviewReasons[] = 'missing_time_in';
        }

        if (! $completeOuts) {
            $reviewReasons[] = 'missing_time_out';
        }

        if ($duplicated) {
            $reviewReasons[] = 'duplicate_dtr_rows';
        }

        return [
            'date' => $date,
            'schedule' => [
                'morning_start' => $schedule['morning_in'],
                'morning_end' => $schedule['morning_out'],
                'afternoon_start' => $schedule['afternoon_in'],
                'afternoon_end' => $schedule['afternoon_out'],
            ],
            'dtr_id' => isset($record->id) ? (int) $record->id : null,
            'dtr_ids' => $ordered->map(fn (object $row) => (int) ($row->id ?? 0))->all(),
            'duplicate_dtr_rows' => $duplicated,
            'raw_time_in' => $record->time_in ?? null,
            'raw_time_out' => $record->time_out ?? null,
            'raw_time_over' => $record->time_over ?? null,
            'normalized_time_ins' => $timeIns->all(),
            'normalized_time_outs' => $timeOuts->all(),
            'normalized_time_overs' => $overtime->all(),
            'usable_time_in_count' => $usableIns->count(),
            'usable_time_out_count' => $usableOuts->count(),
            'time_in_review' => ! $completeIns,
            'time_out_review' => ! $completeOuts,
            'review_required' => $reviewReasons !== [],
            'review_reasons' => $reviewReasons,
            'morning_late_minutes' => $morningLate,
            'afternoon_late_minutes' => $afternoonLate,
            'morning_undertime_minutes' => $morningUndertime,
            'afternoon_undertime_minutes' => $afternoonUndertime,
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'deductible_minutes' => $lateMinutes + $undertimeMinutes,
            'timeline' => $this->timeline($timeIns, $timeOuts, $overtime),
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

    /**
     * One chronological list of every punch for the day. Overtime appears here for the
     * reviewer but is never part of late or undertime.
     */
    private function timeline(Collection $timeIns, Collection $timeOuts, Collection $overtime): array
    {
        return $timeIns->map(fn (string $time) => ['time' => $time, 'type' => 'IN'])
            ->concat($timeOuts->map(fn (string $time) => ['time' => $time, 'type' => 'OUT']))
            ->concat($overtime->map(fn (string $time) => ['time' => $time, 'type' => 'OT']))
            ->sortBy(fn (array $entry) => $this->minutes($entry['time']))
            ->values()
            ->all();
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

        $value = trim((string) $time);

        // Only a real clock value is trusted. Carbon turns stray text into "now", which
        // would silently become a punch or a schedule boundary and corrupt the day.
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?(?:\.\d+)?\s*([AaPp])?\.?[Mm]?\.?$/', $value, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $meridiem = strtolower($matches[3] ?? '');

        if ($meridiem === 'p' && $hours < 12) {
            $hours += 12;
        }

        if ($meridiem === 'a' && $hours === 12) {
            $hours = 0;
        }

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        // Seconds and milliseconds are dropped, never rounded up.
        return sprintf('%02d:%02d', $hours, $minutes);
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
