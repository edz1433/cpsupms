<?php

namespace Tests\Feature;

use App\Services\HrisAttendanceDatabaseService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The DTR arrangement, missing-log detection, late and undertime rules must match what
 * HRIS itself computes, to the whole minute. Each case here mirrors one of the agreed
 * rules so a change in payroll cannot silently drift away from HRIS.
 */
class DtrCalculationTest extends TestCase
{
    private const MON = '2026-08-03'; // a Monday
    private const TUE = '2026-08-04';
    private const SAT = '2026-08-01';

    private function day(array $punches, ?object $schedule = null, string $date = self::MON): array
    {
        $rows = collect($punches)->map(fn (array $row, int $index) => (object) [
            'id' => $row['id'] ?? $index + 1,
            'emp_ID' => 'EMP1',
            'date' => $date,
            'time_in' => $row['in'] ?? null,
            'time_out' => $row['out'] ?? null,
            'time_over' => $row['over'] ?? null,
        ])->values();

        $method = new ReflectionMethod(HrisAttendanceDatabaseService::class, 'attendanceDay');
        $method->setAccessible(true);

        return $method->invoke(app(HrisAttendanceDatabaseService::class), $date, $rows, $schedule);
    }

    private function schedule(array $columns): object
    {
        return (object) $columns;
    }

    /** 1. Seconds and milliseconds are discarded without rounding. */
    public function test_seconds_and_milliseconds_are_truncated_not_rounded(): void
    {
        $day = $this->day([['in' => '08:00:59.999,13:00:59', 'out' => '12:00:00,17:00:00']]);

        $this->assertSame(['08:00', '13:00'], $day['normalized_time_ins']);
        $this->assertSame(0, $day['late_minutes']);
        $this->assertSame(0, $day['undertime_minutes']);

        // One second past the minute boundary is a real minute late.
        $late = $this->day([['in' => '08:01:00,13:00', 'out' => '12:00,17:00']]);
        $this->assertSame(1, $late['late_minutes']);
    }

    /** 2. A normal day on the default schedule. */
    public function test_normal_day_computes_both_halves(): void
    {
        $day = $this->day([['in' => '08:07,13:04', 'out' => '11:54,16:50']]);

        $this->assertSame(7, $day['morning_late_minutes']);
        $this->assertSame(4, $day['afternoon_late_minutes']);
        $this->assertSame(11, $day['late_minutes']);
        $this->assertSame(6, $day['morning_undertime_minutes']);
        $this->assertSame(10, $day['afternoon_undertime_minutes']);
        $this->assertSame(16, $day['undertime_minutes']);
        $this->assertSame(27, $day['deductible_minutes']);
        $this->assertFalse($day['review_required']);
    }

    /** 3. Different weekdays use their own schedule columns. */
    public function test_each_weekday_uses_its_own_schedule(): void
    {
        $schedule = $this->schedule([
            'morn_mon' => '07:00-11:00', 'aft_mon' => '12:00-16:00',
            'morn_tue' => '09:00-12:00', 'aft_tue' => '13:00-18:00',
        ]);

        $monday = $this->day([['in' => '07:05,12:00', 'out' => '11:00,16:00']], $schedule, self::MON);
        $this->assertSame('07:00', $monday['schedule']['morning_start']);
        $this->assertSame(5, $monday['late_minutes']);

        $tuesday = $this->day([['in' => '09:05,13:00', 'out' => '12:00,18:00']], $schedule, self::TUE);
        $this->assertSame('09:00', $tuesday['schedule']['morning_start']);
        $this->assertSame(5, $tuesday['late_minutes']);
    }

    /** 4. A missing or unparseable schedule falls back to the default. */
    public function test_missing_schedule_falls_back_to_the_default(): void
    {
        $none = $this->day([['in' => '08:00,13:00', 'out' => '12:00,17:00']], null);
        $broken = $this->day([['in' => '08:00,13:00', 'out' => '12:00,17:00']], $this->schedule(['morn_mon' => 'not-a-range', 'aft_mon' => '']));

        foreach ([$none, $broken] as $day) {
            $this->assertSame('08:00', $day['schedule']['morning_start']);
            $this->assertSame('12:00', $day['schedule']['morning_end']);
            $this->assertSame('13:00', $day['schedule']['afternoon_start']);
            $this->assertSame('17:00', $day['schedule']['afternoon_end']);
        }
    }

    /** 5. With more than two punches the first and last usable ones are chosen. */
    public function test_extra_punches_select_first_and_last_usable(): void
    {
        $day = $this->day([['in' => '07:58,08:30,13:05', 'out' => '12:02,15:00,17:01']]);

        $this->assertSame('07:58', $day['times']['am_in']);
        $this->assertSame('13:05', $day['times']['pm_in']);
        $this->assertSame('12:02', $day['times']['am_out']);
        $this->assertSame('17:01', $day['times']['pm_out']);
    }

    /** 6. Duplicate punches inside the same minute collapse to one. */
    public function test_duplicate_punches_in_the_same_minute_collapse(): void
    {
        $day = $this->day([['in' => '08:00:10,08:00:45,13:00', 'out' => '12:00,17:00']]);

        $this->assertSame(['08:00', '13:00'], $day['normalized_time_ins']);
        $this->assertSame(2, $day['usable_time_in_count']);
        $this->assertFalse($day['time_in_review']);
    }

    /** 7. A single IN flags only the IN side. */
    public function test_single_time_in_flags_only_the_in_side(): void
    {
        $day = $this->day([['in' => '08:10', 'out' => '11:54,16:50']]);

        $this->assertTrue($day['time_in_review']);
        $this->assertFalse($day['time_out_review']);
        $this->assertSame(0, $day['late_minutes']);
        $this->assertSame(['missing_time_in'], $day['review_reasons']);

        // 9/10. The complete OUT side still computes normally.
        $this->assertSame(16, $day['undertime_minutes']);
    }

    /** 8. A single OUT flags only the OUT side. */
    public function test_single_time_out_flags_only_the_out_side(): void
    {
        $day = $this->day([['in' => '08:07,13:04', 'out' => '16:50']]);

        $this->assertTrue($day['time_out_review']);
        $this->assertFalse($day['time_in_review']);
        $this->assertSame(0, $day['undertime_minutes']);
        $this->assertSame(['missing_time_out'], $day['review_reasons']);

        // The complete IN side still computes normally.
        $this->assertSame(11, $day['late_minutes']);
    }

    /** 11. An IN after afternoon start + 30 minutes is not usable. */
    public function test_late_evening_time_in_is_ignored(): void
    {
        $day = $this->day([['in' => '07:58,13:05,18:30', 'out' => '12:02,17:01']]);

        $this->assertSame(2, $day['usable_time_in_count']);
        $this->assertSame('13:05', $day['times']['pm_in']);
    }

    /** 12. An OUT before morning end - 60 minutes is not usable. */
    public function test_early_morning_time_out_is_ignored(): void
    {
        $day = $this->day([['in' => '07:58,13:05', 'out' => '10:30,12:02,17:01']]);

        $this->assertSame(2, $day['usable_time_out_count']);
        $this->assertSame('12:02', $day['times']['am_out']);
        $this->assertSame('17:01', $day['times']['pm_out']);
    }

    /** 13. Duplicate DTR rows use the greatest id and never double-charge. */
    public function test_duplicate_dtr_rows_use_the_newest_row_and_flag_review(): void
    {
        $day = $this->day([
            ['id' => 10, 'in' => '09:00,13:30', 'out' => '12:00,17:00'],
            ['id' => 42, 'in' => '08:05,13:02', 'out' => '11:58,16:55'],
        ]);

        // Only row 42 is used - row 10 would have produced 90 late minutes.
        $this->assertSame(42, $day['dtr_id']);
        $this->assertSame([10, 42], $day['dtr_ids']);
        $this->assertSame(7, $day['late_minutes']);
        $this->assertSame(7, $day['undertime_minutes']);

        $this->assertTrue($day['duplicate_dtr_rows']);
        $this->assertTrue($day['review_required']);
        $this->assertContains('duplicate_dtr_rows', $day['review_reasons']);
    }

    /** 14. A date with no DTR row is never turned into an incomplete-punch review. */
    public function test_dates_without_a_dtr_row_produce_no_day_at_all(): void
    {
        $method = new ReflectionMethod(HrisAttendanceDatabaseService::class, 'employeeAttendance');
        $method->setAccessible(true);

        $result = $method->invoke(app(HrisAttendanceDatabaseService::class), 'EMP1', new Collection(), null);

        $this->assertSame([], $result['daily']);
        $this->assertSame(0, $result['summary']['review_days']);
        $this->assertSame(0, $result['summary']['total_deductible_minutes']);
    }

    /** 15. Period totals add up across days, and overtime never counts. */
    public function test_period_totals_sum_days_and_exclude_overtime(): void
    {
        $method = new ReflectionMethod(HrisAttendanceDatabaseService::class, 'employeeAttendance');
        $method->setAccessible(true);

        $rows = collect([
            (object) ['id' => 1, 'emp_ID' => 'EMP1', 'date' => self::MON, 'time_in' => '08:07,13:04', 'time_out' => '11:54,16:50', 'time_over' => '18:05,20:00'],
            (object) ['id' => 2, 'emp_ID' => 'EMP1', 'date' => self::TUE, 'time_in' => '08:02', 'time_out' => '12:00,17:00', 'time_over' => null],
        ]);

        $result = $method->invoke(app(HrisAttendanceDatabaseService::class), 'EMP1', $rows, null);

        $this->assertSame(11, $result['summary']['total_late_minutes']);
        $this->assertSame(16, $result['summary']['total_undertime_minutes']);
        $this->assertSame(27, $result['summary']['total_deductible_minutes']);
        $this->assertSame(1, $result['summary']['review_days']);

        // Overtime is displayed on the timeline but adds nothing to the deduction.
        $monday = $result['daily'][0];
        $this->assertSame(['18:05', '20:00'], $monday['normalized_time_overs']);
        $this->assertSame(27, $monday['deductible_minutes']);
        $this->assertSame(
            [['time' => '08:07', 'type' => 'IN'], ['time' => '11:54', 'type' => 'OUT'], ['time' => '13:04', 'type' => 'IN'],
                ['time' => '16:50', 'type' => 'OUT'], ['time' => '18:05', 'type' => 'OT'], ['time' => '20:00', 'type' => 'OT']],
            $monday['timeline']
        );
    }

    /** A weekend DTR row still uses the default schedule rather than being dropped. */
    public function test_weekend_rows_use_the_default_schedule(): void
    {
        $day = $this->day([['in' => '08:05,13:00', 'out' => '12:00,17:00']], null, self::SAT);

        $this->assertSame('08:00', $day['schedule']['morning_start']);
        $this->assertSame(5, $day['late_minutes']);
    }

    /**
     * The review screen must show every punch that was captured and blank only the one
     * that is genuinely absent, so a reviewer can tell "no punch" from "punch we could
     * not use".
     */
    public function test_a_half_missing_day_still_reports_the_captured_punches(): void
    {
        // Only one usable IN, but a complete OUT side.
        $day = $this->day([['in' => '08:10', 'out' => '11:54,16:50', 'over' => '18:05']]);

        $this->assertTrue($day['time_in_review']);
        $this->assertFalse($day['time_out_review']);

        // The captured IN is still reported - it is not blanked out with the missing one.
        $this->assertSame('08:10', $day['times']['am_in']);
        $this->assertNull($day['times']['pm_in']);
        $this->assertSame('11:54', $day['times']['am_out']);
        $this->assertSame('16:50', $day['times']['pm_out']);

        // And the raw evidence is available for the reviewer.
        $this->assertSame(['08:10'], $day['normalized_time_ins']);
        $this->assertSame(
            [['time' => '08:10', 'type' => 'IN'], ['time' => '11:54', 'type' => 'OUT'],
                ['time' => '16:50', 'type' => 'OUT'], ['time' => '18:05', 'type' => 'OT']],
            $day['timeline']
        );
    }

    /**
     * A punch dropped by the plausibility window still has to appear as evidence,
     * otherwise the blank cell looks like no punch was ever made.
     */
    public function test_punches_filtered_out_still_appear_in_the_evidence(): void
    {
        // 06:30 is more than an hour before morning end, so it cannot be an AM OUT.
        $day = $this->day([['in' => '08:00,13:00', 'out' => '06:30']]);

        $this->assertSame(0, $day['usable_time_out_count']);
        $this->assertTrue($day['time_out_review']);
        $this->assertNull($day['times']['am_out']);

        // The punch is still visible to the reviewer.
        $this->assertSame(['06:30'], $day['normalized_time_outs']);
        $this->assertContains(['time' => '06:30', 'type' => 'OUT'], $day['timeline']);
    }
    /** deductible_minutes is always late + undertime. */
    public function test_deductible_minutes_always_equals_late_plus_undertime(): void
    {
        foreach ([
            ['in' => '08:07,13:04', 'out' => '11:54,16:50'],
            ['in' => '08:00,13:00', 'out' => '12:00,17:00'],
            ['in' => '08:10', 'out' => '11:54,16:50'],
        ] as $punches) {
            $day = $this->day([$punches]);
            $this->assertSame($day['late_minutes'] + $day['undertime_minutes'], $day['deductible_minutes']);
        }
    }
}
