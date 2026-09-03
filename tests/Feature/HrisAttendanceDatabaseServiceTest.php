<?php

namespace Tests\Feature;

use App\Services\HrisAttendanceDatabaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HrisAttendanceDatabaseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_dtr_and_schedule_tables_and_calculates_attendance_directly(): void
    {
        config()->set('database.connections.hris', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('hris');

        Schema::connection('hris')->create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('emp_ID');
            $table->unsignedInteger('camp_id');
            $table->string('emp_status')->nullable();
            $table->boolean('stat_1')->default(true);
        });
        Schema::connection('hris')->create('dtrs', function (Blueprint $table) {
            $table->id();
            $table->string('emp_ID');
            $table->date('date');
            $table->text('time_in')->nullable();
            $table->text('time_out')->nullable();
            $table->text('time_over')->nullable();
        });
        Schema::connection('hris')->create('official_times', function (Blueprint $table) {
            $table->id();
            $table->string('empid');

            foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
                $table->string('morn_'.$day)->default('08:00:00-12:00:00');
                $table->string('aft_'.$day)->default('13:00:00-17:00:00');
            }
        });

        DB::connection('hris')->table('employees')->insert([
            'emp_ID' => 'DIRECT-DTR-001',
            'camp_id' => 1,
            'emp_status' => '1',
            'stat_1' => 1,
        ]);
        DB::connection('hris')->table('official_times')->insert([
            'empid' => 'DIRECT-DTR-001',
        ]);
        DB::connection('hris')->table('dtrs')->insert([
            [
                'emp_ID' => 'DIRECT-DTR-001',
                'date' => '2026-08-04',
                'time_in' => '08:15:00,13:05:00',
                'time_out' => '11:50:00,16:40:00',
                'time_over' => '18:01:00,20:02:00',
            ],
            [
                'emp_ID' => 'DIRECT-DTR-001',
                'date' => '2026-08-05',
                'time_in' => '07:55:00',
                'time_out' => '12:01:00,17:05:00',
                'time_over' => null,
            ],
        ]);

        $result = app(HrisAttendanceDatabaseService::class)->tardiness([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-15',
            'campus_id' => 1,
            'emp_status' => 'all',
            'emp_id' => ['DIRECT-DTR-001'],
        ]);

        $this->assertSame('connected', $result['status']);
        $employee = $result['data']['data'][0];
        $this->assertSame('DIRECT-DTR-001', $employee['emp_ID']);
        $this->assertSame(20, $employee['summary']['total_late_minutes']);
        $this->assertSame(30, $employee['summary']['total_undertime_minutes']);
        $this->assertSame(1, $employee['summary']['review_days']);
        $this->assertSame([
            'am_in' => '08:15',
            'am_out' => '11:50',
            'pm_in' => '13:05',
            'pm_out' => '16:40',
            'ot_in' => '18:01',
            'ot_out' => '20:02',
        ], $employee['daily'][0]['times']);
        $this->assertFalse($employee['daily'][0]['time_in_review']);
        $this->assertTrue($employee['daily'][1]['time_in_review']);
        $this->assertDatabaseHas('hris_sync_logs', [
            'request_type' => 'tardiness',
            'status' => 'connected',
        ]);
    }
}
