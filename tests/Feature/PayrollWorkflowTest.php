<?php

namespace Tests\Feature;

use App\Models\AttendanceSummary;
use App\Models\Campus;
use App\Models\Employee;
use App\Models\FundCluster;
use App\Models\MissingLogAppeal;
use App\Models\Office;
use App\Models\PayrollBatch;
use App\Models\Status;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollTemplate;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use App\Services\HrisAttendanceDatabaseService;
use App\Services\HrisDatabaseService;
use App\Services\HrisEmployeeSyncService;
use App\Services\PayrollComputationService;
use App\Services\PayrollEmployeeTypeService;
use App\Services\PayrollFundTypeService;
use App\Services\PayrollSignatoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_does_not_create_employees(): void
    {
        $this->seed();

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_campus_admin_can_generate_draft_with_appeal_adjusted_computation(): void
    {
        $this->seed();

        $campus = Campus::where('code', 'Main')->firstOrFail();
        $user = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();
        $this->mock(HrisDatabaseService::class, function (MockInterface $mock) use ($campus) {
            $mock->shouldReceive('employees')->once()->andReturn([
                'status' => 'connected',
                'data' => ['data' => [[
                    'emp_ID' => 'HRIS-1001',
                    'name' => 'Ana Marie Dela Cruz',
                    'fname' => 'Ana Marie',
                    'mname' => null,
                    'lname' => 'Dela Cruz',
                    'prefix' => null,
                    'suffix' => null,
                    'position' => 'Administrative Aide IV',
                    'emp_dept' => 'Accounting Office',
                    'emp_status' => 'regular',
                    'camp_id' => $campus->id,
                ]]],
            ]);
        });

        $this->mockHrisAttendance([
            'status' => 'success',
            'count' => 1,
            'data' => [[
                'emp_ID' => 'HRIS-1001',
                'name' => 'Ana Marie Dela Cruz',
                'fname' => 'Ana Marie',
                'mname' => null,
                'lname' => 'Dela Cruz',
                'prefix' => null,
                'suffix' => null,
                'position' => 'Administrative Aide IV',
                'emp_dept' => 'Accounting Office',
                'emp_status' => 'regular',
                'camp_id' => $campus->id,
            ]],
        ]);

        app(HrisEmployeeSyncService::class)->sync([], $user);

        $employee = Employee::where('employee_no', 'HRIS-1001')->firstOrFail();
        $employee->update([
            'fund_cluster_id' => $employeeFund->id,
            'monthly_salary' => 32000,
            'rate_per_day' => round(32000 / 22, 2),
            'rate_per_hour' => round((32000 / 22) / 8, 2),
            'rate_per_minute' => round(((32000 / 22) / 8) / 60, 4),
            'tax_rate' => .03,
            'sss_amount' => 800,
            'philhealth_amount' => 500,
            'pagibig_amount' => 200,
            'other_deductions_amount' => 300,
        ]);

        AttendanceSummary::updateOrCreate(
            ['employee_id' => $employee->id, 'payroll_period_id' => $period->id],
            [
                'present_days' => 9,
                'absent_days' => 1,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'missing_log_status' => 'Missing PM out',
                'last_synced_at' => now(),
            ]
        );

        MissingLogAppeal::create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'created_by' => $user->id,
            'reviewed_by' => User::where('email', 'university.payroll@cpsu.edu.ph')->value('id'),
            'attendance_date' => '2026-08-04',
            'missing_log_status' => 'Missing PM out',
            'status' => 'approved',
            'credited_days' => 1,
            'reason' => 'Official travel with approved document.',
            'review_remarks' => 'Approved for payroll credit.',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'Feature test generation',
            'signatories' => $this->signatoriesFor($campus),
        ]);

        $batch = $this->latestBatchFor($employee);
        $line = $batch->lines()->where('employee_id', $employee->id)->firstOrFail();

        // The run covered more than one fund, so it lands back on the campus batch list.
        $response->assertRedirect(route('payroll.index', ['campus' => $campus->id]));
        $this->assertSame(PayrollBatch::DRAFT, $batch->status);
        $this->assertSame('MDS', $batch->template->code);
        $this->assertSame('approved', $line->appeal_status);
        $this->assertEquals(10.00, (float) $line->rendered_days);
        $this->assertEquals(0.00, (float) $line->absent_days);
        $this->assertGreaterThan(0, (float) $line->net_amount_received);
        $this->assertEquals($batch->lines()->sum('net_amount_received'), (float) $batch->total_net);
    }

    public function test_hris_employee_sync_imports_employee_records(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();

        Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'HRIS-EXISTING',
            'full_name' => 'Existing Payroll Employee',
            'office_id' => Office::resolveByName('Old Office')->id,
            'employment_type' => 'regular',
            'monthly_salary' => 25000,
        ]);

        $this->mock(HrisDatabaseService::class, function (MockInterface $mock) use ($campus) {
            $mock->shouldReceive('employees')->once()->withArgs(function (array $filters, User $syncUser) {
                return $filters === [] && $syncUser->email === 'super@cpsu.edu.ph';
            })->andReturn([
                'status' => 'connected',
                'data' => ['data' => [[
                    'emp_ID' => 'HRIS-2001',
                    'name' => 'Roberto Villanueva',
                    'position' => 'Instructor I',
                    'emp_dept' => 'College of Agriculture',
                    'emp_status' => '4',
                    'camp_id' => $campus->id,
                ], [
                    'emp_ID' => 'HRIS-EXISTING',
                    'name' => 'Updated Payroll Employee',
                    'position' => 'Administrative Officer',
                    'emp_dept' => 'Updated Office',
                    'emp_status' => '1',
                    'camp_id' => $campus->id,
                ]]],
            ]);
        });

        $this->actingAs($user)
            ->post(route('employees.sync-hris'))
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'HRIS-2001',
            'full_name' => 'Roberto Villanueva',
            'campus_id' => $campus->id,
            'designation' => 'Instructor I',
            'status_id' => Status::JOB_ORDER,
        ]);
        $this->assertDatabaseHas('employees', [
            'employee_no' => 'HRIS-EXISTING',
            'full_name' => 'Updated Payroll Employee',
            'monthly_salary' => 25000,
        ]);
        $this->assertDatabaseHas('offices', ['office_name' => 'College of Agriculture']);
        $this->assertSame('Updated Office', Employee::where('employee_no', 'HRIS-EXISTING')->firstOrFail()->office_name);
    }

    public function test_hris_sync_fills_in_a_missing_monthly_salary(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();

        Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'HRIS-SALARY-SET',
            'full_name' => 'Payroll Salary Kept',
            'employment_type' => 'regular',
            'monthly_salary' => 25000,
        ]);

        Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'HRIS-SALARY-BLANK',
            'full_name' => 'Payroll Salary Missing',
            'employment_type' => 'regular',
            'monthly_salary' => 0,
        ]);

        $this->mock(HrisDatabaseService::class, function (MockInterface $mock) use ($campus) {
            $mock->shouldReceive('employees')->once()->andReturn([
                'status' => 'connected',
                'data' => ['data' => [[
                    'emp_ID' => 'HRIS-SALARY-SET',
                    'name' => 'Payroll Salary Kept',
                    'emp_status' => '1',
                    'camp_id' => $campus->id,
                    'monthly_salary' => 44000,
                ], [
                    'emp_ID' => 'HRIS-SALARY-BLANK',
                    'name' => 'Payroll Salary Missing',
                    'emp_status' => '1',
                    'camp_id' => $campus->id,
                    'monthly_salary' => 30800,
                ], [
                    'emp_ID' => 'HRIS-SALARY-NONE',
                    'name' => 'No Salary Anywhere',
                    'emp_status' => '1',
                    'camp_id' => $campus->id,
                    'monthly_salary' => 0,
                ]]],
            ]);
        });

        $this->actingAs($user)
            ->post(route('employees.sync-hris'))
            ->assertRedirect();

        $kept = Employee::where('employee_no', 'HRIS-SALARY-SET')->firstOrFail();
        $this->assertEquals(25000, (float) $kept->monthly_salary);

        $filled = Employee::where('employee_no', 'HRIS-SALARY-BLANK')->firstOrFail();
        $this->assertEquals(30800, (float) $filled->monthly_salary);
        $this->assertEquals(1400, (float) $filled->rate_per_day);
        $this->assertEquals(175, (float) $filled->rate_per_hour);
        $this->assertEquals(2.9167, (float) $filled->rate_per_minute);

        $this->assertEquals(0, (float) Employee::where('employee_no', 'HRIS-SALARY-NONE')->firstOrFail()->monthly_salary);
    }

    public function test_campus_admin_cannot_sync_employees_from_hris(): void
    {
        $this->seed();

        $user = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();

        $this->actingAs($user)
            ->post(route('employees.sync-hris'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee('Sync Employees from HRIS');
    }

    public function test_hris_settings_are_only_available_to_hris_administrators(): void
    {
        $this->seed();

        foreach (['super@cpsu.edu.ph', 'university.payroll@cpsu.edu.ph'] as $email) {
            $administrator = User::where('email', $email)->firstOrFail();

            $this->actingAs($administrator)
                ->get(route('settings.hris'))
                ->assertOk()
                ->assertSee('HRIS Settings');
        }

        foreach (['main.payroll@cpsu.edu.ph', 'auditor@cpsu.edu.ph'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('HRIS Settings');

            $this->actingAs($user)
                ->get(route('settings.hris'))
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('settings.hris.check'))
                ->assertForbidden();
        }
    }

    public function test_employees_page_can_search_and_filter_records(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $mainCampus = Campus::where('code', 'Main')->firstOrFail();
        $candoniCampus = Campus::where('code', 'Candoni')->firstOrFail();
        $gaaFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $commonFund = FundCluster::where('fund_source_name', 'Common Fund')->firstOrFail();

        Employee::create([
            'campus_id' => $mainCampus->id,
            'fund_cluster_id' => $gaaFund->id,
            'employee_no' => 'SEARCH-001',
            'full_name' => 'Maribel Santos',
            'office_id' => Office::resolveByName('Registrar Office')->id,
            'designation' => 'Records Officer',
            'employment_type' => 'permanent',
            'monthly_salary' => 30000,
            'is_active' => true,
        ]);

        Employee::create([
            'campus_id' => $candoniCampus->id,
            'fund_cluster_id' => $commonFund->id,
            'employee_no' => 'SEARCH-002',
            'full_name' => 'Rico Valdez',
            'office_id' => Office::resolveByName('Supply Office')->id,
            'designation' => 'Administrative Aide',
            'employment_type' => 'contractual',
            'monthly_salary' => 18000,
            'is_active' => true,
        ]);

        Employee::create([
            'campus_id' => $mainCampus->id,
            'fund_cluster_id' => $gaaFund->id,
            'employee_no' => 'SEARCH-003',
            'full_name' => 'Inactive Payroll Record',
            'office_id' => Office::resolveByName('Accounting Office')->id,
            'designation' => 'Assistant',
            'employment_type' => 'permanent',
            'monthly_salary' => 12000,
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get(route('employees.index', [
                'q' => 'Registrar',
                'campus_id' => $mainCampus->id,
                'fund_cluster_id' => $gaaFund->id,
                'status_id' => Status::REGULAR,
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertSee('Regular')
            ->assertSee('Full-time/Part-time')
            ->assertSee('Part-time/Part-time')
            ->assertSee('Job Order')
            ->assertSee('Maribel Santos')
            ->assertDontSee('Rico Valdez')
            ->assertDontSee('Inactive Payroll Record')
            ->assertSee('1 shown');

        $this->actingAs($user)
            ->get(route('employees.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Inactive Payroll Record')
            ->assertDontSee('Maribel Santos');
    }

    public function test_payroll_admin_can_update_employee_without_changing_employee_id(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $fund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'HRIS-3001',
            'full_name' => 'Original Employee',
            'employment_type' => 'regular',
        ]);

        $this->actingAs($user)
            ->put(route('employees.update', $employee), [
                'employee_no' => 'CHANGED-ID',
                'campus_id' => $campus->id,
                'fund_cluster_id' => $fund->id,
                'full_name' => 'Updated Employee',
                'office_id' => Office::resolveByName('Finance Office')->id,
                'designation' => 'Payroll Officer',
                'status_id' => Status::REGULAR,
                'salary_grade' => 'SG-12',
                'monthly_salary' => 42000,
                'rate_per_day' => 1,
                'rate_per_hour' => 1,
                'rate_per_minute' => 1,
                'tax_rate' => 0.05,
                'tax_status' => 'taxable',
                'bir_sworn_status' => 'submitted',
                'sss_amount' => 1200,
                'philhealth_amount' => 600,
                'philhealth_contribution_type' => 'direct',
                'pagibig_amount' => 200,
                'nsca_mpc_amount' => 150,
                'other_deductions_amount' => 75,
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Employee payroll record updated.');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'employee_no' => 'HRIS-3001',
            'full_name' => 'Updated Employee',
            'designation' => 'Payroll Officer',
        ]);

        $employee->refresh();
        $this->assertEquals(1909.09, (float) $employee->rate_per_day);
        $this->assertEquals(238.64, (float) $employee->rate_per_hour);
        $this->assertEquals(3.9773, (float) $employee->rate_per_minute);
        $this->assertEquals(1750.00, (float) $employee->sss_amount);
        $this->assertEquals(2100.00, (float) $employee->philhealth_amount);
        $this->assertEquals(200.00, (float) $employee->pagibig_amount);

        $this->assertDatabaseMissing('employees', [
            'employee_no' => 'CHANGED-ID',
        ]);
    }

    public function test_employee_update_automates_indirect_philhealth_as_zero(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $fund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'HRIS-INDIRECT',
            'full_name' => 'Indirect PhilHealth Employee',
            'employment_type' => 'contractual',
        ]);

        $this->actingAs($user)
            ->put(route('employees.update', $employee), [
                'campus_id' => $campus->id,
                'fund_cluster_id' => $fund->id,
                'full_name' => 'Indirect PhilHealth Employee',
                'office_id' => Office::resolveByName('Finance Office')->id,
                'designation' => 'Payroll Assistant',
                'status_id' => Status::JOB_ORDER,
                'salary_grade' => null,
                'monthly_salary' => 20000,
                'rate_per_day' => 0,
                'rate_per_hour' => 0,
                'rate_per_minute' => 0,
                'tax_rate' => 0,
                'tax_status' => null,
                'bir_sworn_status' => null,
                'sss_amount' => 0,
                'philhealth_amount' => 999,
                'philhealth_contribution_type' => 'indirect',
                'pagibig_amount' => 0,
                'nsca_mpc_amount' => 0,
                'other_deductions_amount' => 0,
                'is_active' => 1,
            ])
            ->assertRedirect();

        $employee->refresh();
        $this->assertEquals(1000.00, (float) $employee->sss_amount);
        $this->assertEquals(0.00, (float) $employee->philhealth_amount);
        $this->assertSame('indirect', $employee->philhealth_contribution_type);
        $this->assertEquals(200.00, (float) $employee->pagibig_amount);
    }

    public function test_second_half_payroll_skips_scheduled_deductions(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 16-31, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'AUTO-DEDUCT-001',
            'full_name' => 'Second Half Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 44000,
            'tax_rate' => .05,
            'sss_amount' => 1000,
            'philhealth_amount' => 700,
            'pagibig_amount' => 200,
            'nsca_mpc_amount' => 150,
            'other_deductions_amount' => 75,
            'is_active' => true,
        ]);
        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'AUTO-DEDUCT-001',
                'summary' => ['total_late_minutes' => 0, 'review_days' => 0],
                'daily' => [],
            ]],
        ]);

        $response = $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'Second half deduction automation',
            'signatories' => $this->signatoriesFor($campus),
        ]);

        $batch = $this->latestBatchFor($employee);
        $line = $batch->lines()->where('employee_id', $employee->id)->firstOrFail();

        $response->assertRedirect(route('payroll.index', ['campus' => $campus->id]));
        $this->assertEquals(0.00, (float) $line->tax_amount);
        $this->assertEquals(0.00, (float) $line->sss);
        $this->assertEquals(0.00, (float) $line->philhealth);
        $this->assertEquals(0.00, (float) $line->pagibig);
        $this->assertEquals(0.00, (float) $line->nsca_mpc);
        $this->assertEquals(0.00, (float) $line->other_deductions);
        $this->assertEquals(0.00, (float) $line->total_deduction);
        $this->assertFalse($line->computed_columns['scheduled_deductions_applied']);
    }

    public function test_generated_payroll_rates_are_automated_from_monthly_salary(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'AUTO-RATE-001',
            'full_name' => 'Automated Rate Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'rate_per_day' => 1,
            'rate_per_hour' => 1,
            'rate_per_minute' => 1,
            'is_active' => true,
        ]);
        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'AUTO-RATE-001',
                'summary' => ['total_late_minutes' => 0, 'review_days' => 0],
                'daily' => [],
            ]],
        ]);

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'Rate automation',
            'signatories' => $this->signatoriesFor($campus),
        ]);

        $batch = $this->latestBatchFor($employee);
        $line = $batch->lines()->where('employee_id', $employee->id)->firstOrFail();

        // Regular pay divides by the month's working days: August 2026 has 21 weekdays.
        $this->assertEquals(1047.62, (float) $line->rate_per_day);
        $this->assertEquals(130.95, (float) $line->rate_per_hour);
        $this->assertEquals(2.1825, (float) $line->rate_per_minute);
        $this->assertEquals(1100.00, (float) $line->sss);
        $this->assertEquals(1100.00, (float) $line->philhealth);
        $this->assertEquals(200.00, (float) $line->pagibig);
        $this->assertEquals(21, $line->computed_columns['working_days']);
        $this->assertEquals(8, $line->computed_columns['hours_per_day']);
    }

    public function test_generation_imports_hris_tardiness_flags_missing_logs_and_blocks_submission_until_resolved(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'HRIS-TARDY-001',
            'full_name' => 'Tardy Review Employee',
            'designation' => 'Instructor I',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'HRIS-TARDY-001',
                'summary' => [
                    'total_late_minutes' => 45,
                    'review_days' => 2,
                ],
                'daily' => [
                    ['date' => '2026-08-06', 'times' => ['am_in' => '7:29', 'am_out' => '11:32', 'pm_in' => '11:46', 'pm_out' => '7:08', 'ot_in' => null, 'ot_out' => null]],
                    ['date' => '2026-08-03', 'punches' => ['am_in' => '7:54', 'am_out' => '12:24', 'pm_in' => '12:26', 'pm_out' => '6:33', 'time_in_count' => 2, 'time_out_count' => 2]],
                    ['date' => '2026-08-04', 'time_in_review' => true, 'time_out_review' => false, 'late_minutes' => 45, 'times' => ['am_in' => null, 'am_out' => '11:53', 'pm_in' => '12:05', 'pm_out' => '6:32', 'ot_in' => null, 'ot_out' => null], 'punches' => ['am_out' => '10:00', 'pm_out' => '15:00']],
                    ['date' => '2026-08-05', 'time_in_review' => false, 'time_out_review' => true, 'half_day' => true, 'punches' => ['AM IN' => '7:41', 'AM OUT' => '11:53', 'PM IN' => '12:05']],
                    ['date' => '2026-08-08', 'time_in_review' => true, 'time_out_review' => true, 'absent' => true],
                    ['date' => '2026-08-10', 'absent_days' => 1],
                ],
            ]],
        ]);

        $response = $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'HRIS tardiness test',
            'signatories' => $this->signatoriesFor($campus),
        ]);

        $batch = $this->latestBatchFor($employee);
        $line = $batch->lines()->where('employee_id', $employee->id)->firstOrFail();

        $response->assertRedirect(route('payroll.index', ['campus' => $campus->id]));
        $this->assertEquals(45, (int) $line->late_minutes);
        // 45 late minutes at the August 2026 per-minute rate (22000 / 21 days / 8h / 60).
        $this->assertEquals(98.21, (float) $line->late_deduction);
        $this->assertStringContainsString('Needs HR Review', $line->missing_log_status);
        $this->assertStringContainsString('missing time-in', $line->missing_log_status);
        $this->assertStringContainsString('missing time-out', $line->missing_log_status);
        $this->assertStringNotContainsString('Late 45 min', $line->missing_log_status);
        $this->assertStringNotContainsString('Half day', $line->missing_log_status);
        $this->assertStringNotContainsString('Whole-day absence', $line->missing_log_status);
        $this->assertStringNotContainsString('Aug 08', $line->missing_log_status);
        $this->assertCount(4, $line->computed_columns['attendance_review_items']);
        $dtrItems = collect($line->computed_columns['attendance_review_items']);
        $this->assertSame(['am_in', 'am_out', 'pm_in', 'pm_out', 'ot_in', 'ot_out'], array_keys($dtrItems->firstWhere('date', '2026-08-06')['times']));
        $this->assertSame('07:29', $dtrItems->firstWhere('date', '2026-08-06')['times']['am_in']);
        $this->assertSame([], $dtrItems->firstWhere('date', '2026-08-06')['issues']);
        $this->assertSame('07:54', $dtrItems->firstWhere('date', '2026-08-03')['times']['am_in']);
        $this->assertSame('06:33', $dtrItems->firstWhere('date', '2026-08-03')['times']['pm_out']);
        $this->assertSame('11:53', $dtrItems->firstWhere('date', '2026-08-04')['times']['am_out']);
        $this->assertSame('06:32', $dtrItems->firstWhere('date', '2026-08-04')['times']['pm_out']);
        $this->assertSame('07:41', $dtrItems->firstWhere('date', '2026-08-05')['times']['am_in']);
        $this->assertSame('12:05', $dtrItems->firstWhere('date', '2026-08-05')['times']['pm_in']);
        $this->assertSame(['missing time-in'], $dtrItems->firstWhere('date', '2026-08-04')['issues']);
        $this->assertSame(['missing time-out'], $dtrItems->firstWhere('date', '2026-08-05')['issues']);
        $this->assertNull($dtrItems->firstWhere('date', '2026-08-10'));
        $snapshotDtrItems = collect($batch->snapshot['tardiness_sync']['review_items_by_employee_no']['HRIS-TARDY-001']);
        $this->assertSame('07:29', $snapshotDtrItems->firstWhere('date', '2026-08-06')['times']['am_in']);
        $this->assertEquals(1, $batch->employees_with_missing_logs);

        $this->actingAs($user)
            ->get(route('payroll.show', $batch))
            ->assertOk()
            ->assertSee('line-needs-review', false)
            ->assertSee('data-review-modal-trigger', false)
            ->assertSee('data-review-items', false)
            ->assertSee('line-review-modal', false)
            ->assertSee('Open DTR review for Tardy Review Employee', false)
            ->assertSee('Corrected DTR')
            ->assertSee('On Leave')
            ->assertSee('Half Day')
            ->assertSee('DTR time values were not captured for this payroll line', false)
            ->assertDontSee('line-review-trigger', false)
            ->assertDontSee('<span>Review</span>', false)
            ->assertDontSee('Negative Net')
            ->assertDontSee('HR Attendance Review');

        $this->actingAs($user)
            ->post(route('payroll.submit', $batch), ['remarks' => 'Attempt submit with unresolved attendance issue.'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->post(route('payroll.lines.resolve-attendance', [$batch, $line]), [
                'review_items' => [
                    [
                        'date' => '2026-08-04',
                        'date_label' => 'Aug 04',
                        'summary' => 'missing time-in',
                        'resolution' => 'corrected_dtr',
                        'remarks' => 'DTR corrected by HR.',
                    ],
                    [
                        'date' => '2026-08-05',
                        'date_label' => 'Aug 05',
                        'summary' => 'missing time-out',
                        'resolution' => 'half_day',
                        'remarks' => 'Half day verified by supervisor.',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Attendance review issue resolved for Tardy Review Employee.');

        $line->refresh();
        $batch->refresh();
        $this->assertSame('No issue', $line->missing_log_status);
        $this->assertSame('approved', $line->appeal_status);
        $this->assertSame('corrected_dtr', $line->computed_columns['attendance_review_resolutions'][0]['resolution']);
        $this->assertSame('half_day', $line->computed_columns['attendance_review_resolutions'][1]['resolution']);
        $this->assertCount(2, $line->computed_columns['attendance_review_resolutions']);
        $this->assertStringContainsString('Aug 04: Corrected DTR - DTR corrected by HR.', $line->remarks);
        $this->assertStringContainsString('Aug 05: Half Day - Half day verified by supervisor.', $line->remarks);
        $this->assertEquals(0, $batch->employees_with_missing_logs);

        $this->actingAs($user)
            ->post(route('payroll.submit', $batch), ['remarks' => 'Attendance reviewed and payroll submitted.'])
            ->assertRedirect();

        $this->assertSame(PayrollBatch::SUBMITTED, $batch->refresh()->status);
    }

    public function test_hris_attendance_database_failure_blocks_submission_without_fake_employee_review_rows(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'HRIS-DB-FAIL',
            'full_name' => 'Database Failure Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        $this->mock(HrisAttendanceDatabaseService::class, function (MockInterface $mock) {
            $mock->shouldReceive('tardiness')->once()->andReturn([
                'status' => 'unavailable',
                'message' => 'Unable to read attendance from the HRIS database.',
            ]);
        });

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'HRIS outage test',
            'signatories' => $this->signatoriesFor($campus),
        ])->assertRedirect();

        $batch = $this->latestBatchFor($employee);
        $line = $batch->lines()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame('unavailable', $batch->snapshot['tardiness_sync']['status']);
        $this->assertTrue($batch->snapshot['tardiness_sync']['blocking']);
        $this->assertSame('No issue', $line->missing_log_status);
        $this->assertEquals(0, $batch->employees_with_missing_logs);

        $snapshot = $batch->snapshot;
        $snapshot['tardiness_sync'] = [
            'status' => 'api error',
            'message' => 'HRIS returned HTTP 404.',
            'blocking' => true,
        ];
        $batch->update(['snapshot' => $snapshot]);

        $this->actingAs($user)
            ->get(route('payroll.show', $batch))
            ->assertOk()
            ->assertSee('This draft contains an old API sync failure.')
            ->assertSee('Refresh Attendance')
            ->assertDontSee('HRIS returned HTTP');

        $this->actingAs($user)
            ->post(route('payroll.submit', $batch), ['remarks' => 'Attempt submit during HRIS outage.'])
            ->assertStatus(422);

        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'HRIS-DB-FAIL',
                'summary' => ['total_late_minutes' => 10, 'total_undertime_minutes' => 0, 'review_days' => 0],
                'daily' => [[
                    'date' => '2026-08-04',
                    'times' => ['am_in' => '08:10', 'am_out' => '12:00', 'pm_in' => '13:00', 'pm_out' => '17:00'],
                ]],
            ]],
        ]);

        $this->actingAs($user)
            ->post(route('payroll.refresh-attendance', $batch))
            ->assertRedirect()
            ->assertSessionHas('status', 'Payroll attendance refreshed directly from the HRIS database.');

        $this->assertSame('connected', $batch->refresh()->snapshot['tardiness_sync']['status']);
        $this->assertSame(10, (int) $line->refresh()->late_minutes);
    }

    public function test_payroll_show_hydrates_existing_review_items_with_missing_times(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'HYDRATE-DTR-001',
            'full_name' => 'Hydrate DTR Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        $hasHydrationPunches = false;
        $initialTardinessResponse = [
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'HYDRATE-DTR-001',
                'summary' => ['total_late_minutes' => 0, 'review_days' => 1],
                'daily' => [['date' => '2026-08-04', 'time_in_review' => true, 'time_out_review' => false]],
            ]],
        ];
        $hydratedTardinessResponse = [
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'HYDRATE-DTR-001',
                'summary' => ['total_late_minutes' => 0, 'review_days' => 1],
                'daily' => [[
                    'date' => '2026-08-04',
                    'time_in_review' => true,
                    'time_out_review' => false,
                    'times' => [
                        'am_in' => null,
                        'am_out' => '12:5',
                        'pm_in' => '12:07',
                        'pm_out' => '17:7',
                        'ot_in' => '18:1',
                        'ot_out' => '20:00',
                    ],
                ]],
            ]],
        ];

        $this->mock(HrisAttendanceDatabaseService::class, function (MockInterface $mock) use (&$hasHydrationPunches, $hydratedTardinessResponse, $initialTardinessResponse) {
            $mock->shouldReceive('tardiness')->twice()->andReturnUsing(function () use (&$hasHydrationPunches, $hydratedTardinessResponse, $initialTardinessResponse) {
                return [
                    'status' => 'connected',
                    'data' => $hasHydrationPunches ? $hydratedTardinessResponse : $initialTardinessResponse,
                ];
            });
        });

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'Hydration test',
            'signatories' => $this->signatoriesFor($campus),
        ])->assertRedirect();

        $batch = $this->latestBatchFor($employee);
        $line = $batch->lines()->where('employee_id', $employee->id)->firstOrFail();
        $computedColumns = $line->computed_columns;
        $computedColumns['attendance_review_resolutions'] = [
            ['date' => '2026-08-04', 'resolution' => 'corrected_dtr', 'remarks' => 'Already reviewed by HR.'],
        ];
        $computedColumns['attendance_review_items'] = [[
            'date' => '2026-08-04',
            'date_label' => 'Aug 04',
            'weekday' => 'Tue',
            'summary' => 'missing time-in',
            'issues' => ['missing time-in'],
            'times' => [
                'am_in' => null,
                'am_out' => null,
                'pm_in' => null,
                'pm_out' => null,
                'ot_in' => null,
                'ot_out' => null,
            ],
            'resolution' => ['date' => '2026-08-04', 'resolution' => 'corrected_dtr', 'remarks' => 'Already reviewed by HR.'],
        ]];
        $line->update([
            'computed_columns' => $computedColumns,
            'remarks' => 'Existing payroll remarks.',
        ]);
        $hasHydrationPunches = true;

        $this->actingAs($user)
            ->get(route('payroll.show', $batch))
            ->assertOk();

        $line->refresh();
        $item = $line->computed_columns['attendance_review_items'][0];

        $this->assertSame([
            'am_in' => null,
            'am_out' => '12:05',
            'pm_in' => '12:07',
            'pm_out' => '17:07',
            'ot_in' => '18:01',
            'ot_out' => '20:00',
        ], $item['times']);
        $this->assertSame('corrected_dtr', $item['resolution']['resolution']);
        $this->assertSame('Already reviewed by HR.', $item['resolution']['remarks']);
        $this->assertSame($computedColumns['attendance_review_resolutions'], $line->computed_columns['attendance_review_resolutions']);
        $this->assertSame('Existing payroll remarks.', $line->remarks);
    }

    public function test_payroll_show_hydrates_fallback_review_items_from_missing_log_status(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'FALLBACK-DTR-001',
            'full_name' => 'Fallback DTR Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        $batch = PayrollBatch::create([
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $mainFund->id,
            'created_by' => $user->id,
            'batch_no' => 'TEST-FALLBACK-DTR',
            'status' => PayrollBatch::DRAFT,
            'total_employees' => 1,
            'employees_with_missing_logs' => 1,
            'snapshot' => [
                'payroll_employee_type_label' => 'Regular',
                'tardiness_sync' => ['status' => 'connected'],
            ],
        ]);

        $line = PayrollLine::create([
            'payroll_batch_id' => $batch->id,
            'employee_id' => $employee->id,
            'line_no' => 1,
            'employee_no' => $employee->employee_no,
            'employee_name' => $employee->full_name,
            'missing_log_status' => 'Needs HR Review: Aug 04: missing time-in, missing time-out',
            'computed_columns' => ['attendance_review_items' => []],
        ]);

        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'FALLBACK-DTR-001',
                'summary' => ['total_late_minutes' => 0, 'review_days' => 1],
                'daily' => [[
                    'date' => '2026-08-04',
                    'time_in_review' => true,
                    'time_out_review' => true,
                    'times' => [
                        'am_in' => '07:01',
                        'am_out' => '12:05',
                        'pm_in' => '12:07',
                        'pm_out' => '17:07',
                        'ot_in' => null,
                        'ot_out' => null,
                    ],
                ]],
            ]],
        ]);

        $this->actingAs($user)
            ->get(route('payroll.show', $batch))
            ->assertOk()
            ->assertSee('12:05');

        $line->refresh();
        $item = $line->computed_columns['attendance_review_items'][0];

        $this->assertSame('2026-08-04', $item['date']);
        $this->assertSame(['missing time-in', 'missing time-out'], $item['issues']);
        $this->assertSame('07:01', $item['times']['am_in']);
        $this->assertSame('12:05', $item['times']['am_out']);
        $this->assertSame('12:07', $item['times']['pm_in']);
        $this->assertSame('17:07', $item['times']['pm_out']);
    }

    public function test_attendance_review_items_can_be_bulk_resolved(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        foreach (['BULK-REVIEW-001' => 'Bulk Review One', 'BULK-REVIEW-002' => 'Bulk Review Two'] as $employeeNo => $name) {
            Employee::create([
                'campus_id' => $campus->id,
                'fund_cluster_id' => $employeeFund->id,
                'employee_no' => $employeeNo,
                'full_name' => $name,
                'employment_type' => 'regular',
                'monthly_salary' => 22000,
                'is_active' => true,
            ]);
        }

        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [
                [
                    'emp_ID' => 'BULK-REVIEW-001',
                    'summary' => ['total_late_minutes' => 0, 'review_days' => 1],
                    'daily' => [['date' => '2026-08-04', 'time_in_review' => true, 'time_out_review' => false]],
                ],
                [
                    'emp_ID' => 'BULK-REVIEW-002',
                    'summary' => ['total_late_minutes' => 0, 'review_days' => 1],
                    'daily' => [['date' => '2026-08-05', 'time_in_review' => false, 'time_out_review' => true]],
                ],
            ],
        ]);

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'Bulk review test',
            'signatories' => $this->signatoriesFor($campus),
        ])->assertRedirect();

        $batch = $this->latestBatchFor(Employee::where('employee_no', 'BULK-REVIEW-001')->firstOrFail());
        $lineIds = $batch->lines()
            ->whereIn('employee_no', ['BULK-REVIEW-001', 'BULK-REVIEW-002'])
            ->pluck('id')
            ->all();

        $this->assertEquals(2, $batch->employees_with_missing_logs);

        $this->actingAs($user)
            ->post(route('payroll.attendance-review.bulk-resolve', $batch), [
                'line_ids' => $lineIds,
                'remarks' => 'HR reviewed selected items.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2 attendance review items resolved.');

        $batch->refresh();
        $this->assertEquals(0, $batch->employees_with_missing_logs);
        $this->assertEquals(2, $batch->lines()->whereIn('id', $lineIds)->where('missing_log_status', 'No issue')->count());
    }

    public function test_payroll_signatories_are_saved_editable_and_rendered_as_pdf(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();
        $signatoryFund = FundCluster::where('fund_source_name', 'Common Fund')->firstOrFail();

        $payrollEmployee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'PDF-LINE-001',
            'full_name' => 'Payroll Report Employee',
            'designation' => 'Instructor I',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);
        $prepared = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $signatoryFund->id,
            'employee_no' => 'SIGN-001',
            'full_name' => 'Prepared Employee',
            'designation' => 'Payroll Officer',
            'employment_type' => 'regular',
            'is_active' => true,
        ]);
        $certified = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $signatoryFund->id,
            'employee_no' => 'SIGN-002',
            'full_name' => 'Certified Employee',
            'designation' => 'Accountant III',
            'employment_type' => 'regular',
            'is_active' => true,
        ]);
        $approved = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $signatoryFund->id,
            'employee_no' => 'SIGN-003',
            'full_name' => 'Approved Employee',
            'designation' => 'SUC President II',
            'employment_type' => 'regular',
            'is_active' => true,
        ]);
        $payment = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $signatoryFund->id,
            'employee_no' => 'SIGN-004',
            'full_name' => 'Payment Employee',
            'designation' => 'Cashier III',
            'employment_type' => 'regular',
            'is_active' => true,
        ]);
        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'PDF-LINE-001',
                'summary' => ['total_late_minutes' => 0, 'review_days' => 0],
                'daily' => [],
            ]],
        ]);

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'PDF signatory test',
            'signatories' => [
                'prepared_by' => $prepared->id,
                'certified_correct_by' => $certified->id,
                'approved_by' => $approved->id,
                'certified_payment_by' => $payment->id,
            ],
        ])->assertRedirect();

        $batch = $this->latestBatchFor($payrollEmployee);
        $this->assertDatabaseHas('payroll_lines', [
            'payroll_batch_id' => $batch->id,
            'employee_id' => $payrollEmployee->id,
        ]);
        $this->assertSame('Prepared Employee', $batch->snapshot['signatories']['prepared_by']['name']);
        $this->assertSame('Payroll Officer', $batch->snapshot['signatories']['prepared_by']['designation']);

        $showResponse = $this->actingAs($user)
            ->get(route('payroll.show', $batch));

        $showResponse
            ->assertOk()
            ->assertSee('data-signatories-open', false)
            ->assertSee('signatories-modal', false)
            ->assertDontSee('<section id="signatories"', false);
        $this->assertSame(4, substr_count($showResponse->getContent(), 'data-employee-search'));

        $this->actingAs($user)
            ->put(route('payroll.signatories.update', $batch), [
                'signatories' => [
                    'prepared_by' => $certified->id,
                    'certified_correct_by' => $prepared->id,
                    'approved_by' => $approved->id,
                    'certified_payment_by' => $payment->id,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Payroll report signatories updated.');

        $batch->refresh();
        $this->assertSame('Certified Employee', $batch->snapshot['signatories']['prepared_by']['name']);

        $batch->update(['status' => PayrollBatch::APPROVED]);

        $response = $this->actingAs($user)->get(route('payroll.printable', $batch));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $excel = $this->actingAs($user)->get(route('payroll.export', $batch));
        $excel->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $excel->headers->get('content-type'));
        $this->assertStringStartsWith('PK', $excel->getContent());

        $path = tempnam(sys_get_temp_dir(), 'payroll-workbook-').'.xlsx';
        file_put_contents($path, $excel->getContent());
        $workbook = IOFactory::load($path);
        @unlink($path);

        $this->assertSame(1, $workbook->getSheetCount());
        $this->assertSame('MDS', $workbook->getActiveSheet()->getTitle());
        $this->assertSame('Broadway BT', $workbook->getActiveSheet()->getStyle('A1')->getFont()->getName());
        $this->assertSame('Arial', $workbook->getActiveSheet()->getStyle('B10')->getFont()->getName());
        $this->assertSame('Payroll Report Employee', $workbook->getActiveSheet()->getCell('B10')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_campus_admin_cannot_view_another_campus_payroll(): void
    {
        $this->seed();

        $mainAdmin = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();
        $otherCampus = Campus::where('code', 'San Carlos')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $fund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $creator = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();

        $batch = PayrollBatch::create([
            'campus_id' => $otherCampus->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $creator->id,
            'batch_no' => 'TEST-OTHER-CAMPUS',
            'status' => PayrollBatch::DRAFT,
        ]);

        $this->actingAs($mainAdmin)
            ->get(route('payroll.show', $batch))
            ->assertForbidden();
    }

    public function test_draft_payroll_batch_can_be_deleted(): void
    {
        $this->seed();

        $user = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $fund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();

        $batch = PayrollBatch::create([
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $user->id,
            'batch_no' => 'TEST-DRAFT-DELETE',
            'status' => PayrollBatch::DRAFT,
        ]);

        $this->actingAs($user)
            ->delete(route('payroll.destroy', $batch))
            ->assertRedirect(route('payroll.index'))
            ->assertSessionHas('status', 'Draft payroll batch deleted.');

        $this->assertDatabaseMissing('payroll_batches', [
            'id' => $batch->id,
        ]);
    }

    public function test_non_draft_payroll_batch_cannot_be_deleted(): void
    {
        $this->seed();

        $user = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $fund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();

        $batch = PayrollBatch::create([
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $user->id,
            'batch_no' => 'TEST-SUBMITTED-DELETE',
            'status' => PayrollBatch::SUBMITTED,
        ]);

        $this->actingAs($user)
            ->delete(route('payroll.destroy', $batch))
            ->assertStatus(422);

        $this->assertDatabaseHas('payroll_batches', [
            'id' => $batch->id,
            'status' => PayrollBatch::SUBMITTED,
        ]);
    }

    public function test_payroll_index_lists_campuses_as_a_submenu_instead_of_a_table_column(): void
    {
        $this->seed();

        $super = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $main = Campus::where('code', 'Main')->firstOrFail();
        $sanCarlos = Campus::where('code', 'San Carlos')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $fund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();

        $mainBatch = PayrollBatch::create([
            'campus_id' => $main->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $super->id,
            'batch_no' => 'TEST-MAIN-BATCH',
            'status' => PayrollBatch::DRAFT,
        ]);

        PayrollBatch::create([
            'campus_id' => $sanCarlos->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $super->id,
            'batch_no' => 'TEST-SAN-CARLOS-BATCH',
            'status' => PayrollBatch::APPROVED,
        ]);

        $all = $this->actingAs($super)->get(route('payroll.index'))->assertOk();
        $all->assertSee('class="campus-menu"', false);
        $all->assertSee('All campuses');
        $all->assertSee(route('payroll.index', ['campus' => $main->id]), false);
        $all->assertSee(route('payroll.index', ['campus' => $sanCarlos->id]), false);
        $all->assertSee('TEST-MAIN-BATCH');
        $all->assertSee('TEST-SAN-CARLOS-BATCH');

        $scoped = $this->actingAs($super)->get(route('payroll.index', ['campus' => $main->id]))->assertOk();
        $scoped->assertSee('TEST-MAIN-BATCH');
        $scoped->assertDontSee('TEST-SAN-CARLOS-BATCH');
        $scoped->assertSee($main->name);

        $html = $scoped->getContent();
        $tableHead = substr($html, strpos($html, '<thead>'), 400);
        $this->assertStringNotContainsString('<th>Campus</th>', $tableHead);
        // The generate modal opens already scoped to the campus picked in the submenu.
        $this->assertStringContainsString('<option value="'.$main->id.'" selected>'.e($main->name).'</option>', $html);
        $this->assertSame($mainBatch->campus_id, $main->id);
    }

    public function test_payroll_index_campus_submenu_is_scoped_to_the_campus_administrator(): void
    {
        $this->seed();

        $mainAdmin = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();
        $main = Campus::where('code', 'Main')->firstOrFail();
        $sanCarlos = Campus::where('code', 'San Carlos')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $fund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $super = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();

        PayrollBatch::create([
            'campus_id' => $sanCarlos->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $super->id,
            'batch_no' => 'TEST-SAN-CARLOS-BATCH',
            'status' => PayrollBatch::DRAFT,
        ]);

        $response = $this->actingAs($mainAdmin)
            ->get(route('payroll.index', ['campus' => $sanCarlos->id]))
            ->assertOk();

        $response->assertDontSee('TEST-SAN-CARLOS-BATCH');
        $response->assertDontSee('All campuses');
        $response->assertSee($main->name);
        $this->assertStringNotContainsString(route('payroll.index', ['campus' => $sanCarlos->id]), $response->getContent());
    }

    public function test_payroll_create_preselects_the_campus_chosen_in_the_submenu(): void
    {
        $this->seed();

        $super = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $sanCarlos = Campus::where('code', 'San Carlos')->firstOrFail();

        $html = $this->actingAs($super)
            ->get(route('payroll.create', ['campus' => $sanCarlos->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<option value="'.$sanCarlos->id.'" selected>'.e($sanCarlos->name).'</option>', $html);
    }

    public function test_payroll_index_generates_payroll_through_a_modal_instead_of_a_separate_page(): void
    {
        $this->seed();

        $super = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $html = $this->actingAs($super)->get(route('payroll.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="payroll-create-modal"', $html);
        $this->assertStringContainsString('data-open-payroll-create', $html);
        $this->assertStringContainsString('action="'.route('payroll.store').'"', $html);
        $this->assertStringContainsString('name="signatories[prepared_by]"', $html);

        // The trigger opens the dialog; it no longer navigates to the create page.
        $this->assertStringNotContainsString('href="'.route('payroll.create').'"', $html);
        $this->assertStringNotContainsString('data-auto-open', $html);
    }

    public function test_payroll_index_hides_the_generate_modal_from_users_who_cannot_manage_payroll(): void
    {
        $this->seed();

        $auditor = User::where('email', 'auditor@cpsu.edu.ph')->firstOrFail();
        $html = $this->actingAs($auditor)->get(route('payroll.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="payroll-create-modal"', $html);
        $this->assertStringNotContainsString('data-open-payroll-create', $html);
    }

    public function test_failed_generation_returns_to_the_payroll_index_with_the_modal_reopened(): void
    {
        $this->seed();

        $user = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();

        $response = $this->actingAs($user)
            ->from(route('payroll.index'))
            ->followingRedirects()
            ->post(route('payroll.store'), [
                'campus_id' => $user->campus_id,
                'payroll_period_id' => '',
                'payroll_employee_type' => 'regular',
                'remarks' => 'Missing period and fund.',
            ])
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('data-auto-open="1"', $html);
        $this->assertStringContainsString('Missing period and fund.', $html);
        $this->assertDatabaseCount('payroll_batches', 0);
    }

    public function test_authenticated_payroll_pages_render(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();

        $this->actingAs($user)->get('/home')->assertRedirect('/dashboard');
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('employees.index'))->assertOk();
        $this->actingAs($user)->get(route('fund-clusters.index'))->assertOk();
        $this->actingAs($user)->get(route('payroll.index'))->assertOk();
        $this->actingAs($user)->get(route('payroll.create'))->assertOk();
        $this->actingAs($user)->get(route('settings.hris'))->assertOk();
    }

    public function test_desktop_sidebar_toggle_is_rendered_inside_the_sidebar_brand_row(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $response = $this->actingAs($user)->get(route('settings.hris'))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'class="sidebar-toggle"'));
        $this->assertMatchesRegularExpression('/<aside class="sidebar"[^>]*>.*<div class="brand-row">.*data-sidebar-toggle.*<\/div>.*<\/aside>/s', $html);
        $this->assertStringContainsString('sidebar-collapse-icon', $html);
        $this->assertStringContainsString('sidebar-expand-icon', $html);
        $this->assertStringContainsString('aria-controls="primary-sidebar"', $html);
        $this->assertStringContainsString('.sidebar-toggle{position:absolute;right:8px', $html);
        $this->assertStringContainsString('body.sidebar-collapsed .sidebar{width:80px}', $html);
        $this->assertStringNotContainsString('<button class="ghost-btn desktop-sidebar-btn"', $html);
    }

    public function test_collapsed_sidebar_toggle_sits_inside_the_rail_instead_of_hanging_off_the_edge(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $html = $this->actingAs($user)->get(route('payroll.index'))->assertOk()->getContent();

        // Pinned inside the rail, never overhanging it on a negative offset.
        $this->assertStringNotContainsString('body.sidebar-collapsed .sidebar-toggle{right:-', $html);
        // The header keeps the expanded 70px height.
        $this->assertStringContainsString('body.sidebar-collapsed .brand-row{height:70px;flex-direction:row', $html);

        // The chevron is a bare icon pinned right, out of flow, so the seal stays centred;
        // the zero-width brand text must not leave a flex gap behind either.
        $this->assertStringContainsString('body.sidebar-collapsed .sidebar-toggle{position:absolute;right:2px;top:13px;width:20px;height:44px;border:0;border-radius:6px;background:transparent', $html);
        $this->assertStringContainsString('body.sidebar-collapsed .brand{width:auto;height:70px;flex:0 0 auto;justify-content:center;gap:0;padding:0}', $html);

        // The seal carries no decoration the expanded state lacks.
        $this->assertStringContainsString('body.sidebar-collapsed .brand img{height:36px;width:36px;padding:2px;box-shadow:none}', $html);
    }

    public function test_payroll_campus_summary_cells_share_one_fixed_width(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $html = $this->actingAs($user)->get(route('payroll.index'))->assertOk()->getContent();

        // Six equal columns, so a four-digit count never reflows a cell.
        $this->assertStringContainsString('.campus-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))', $html);
        $this->assertStringContainsString('@media(min-width:1500px){.campus-summary{grid-template-columns:repeat(6,minmax(0,1fr))}}', $html);
        $this->assertStringContainsString('@media(max-width:620px){.campus-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}', $html);
        $this->assertStringNotContainsString('.campus-stat+.campus-stat{border-left', $html);
    }

    public function test_payroll_periods_are_generated_for_every_month_of_the_current_year(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 08:00:00'));

        try {
            $this->seed();

            $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
            $createResponse = $this->actingAs($user)->get(route('payroll.create'))->assertOk();

            // Two semi-monthly windows for each of the twelve months, no hand-entry.
            $this->assertSame(24, PayrollPeriod::whereNull('campus_id')->count());

            foreach ([
                'January 1-15, 2026',
                'February 16-28, 2026',
                'August 1-15, 2026',
                'August 16-31, 2026',
                'December 16-31, 2026',
            ] as $period) {
                $this->assertDatabaseHas('payroll_periods', ['name' => $period]);
                $createResponse->assertSee($period);
            }

            // The year boundary is respected on both ends.
            $this->assertDatabaseMissing('payroll_periods', ['name' => 'December 16-31, 2025']);
            $this->assertDatabaseMissing('payroll_periods', ['name' => 'January 1-15, 2027']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_generate_payroll_lists_every_main_fund_instead_of_asking_for_one(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();

        $this->actingAs($user)
            ->get(route('payroll.create'))
            ->assertOk()
            // The fund is never chosen - one run always covers every fund.
            ->assertDontSee('name="fund_cluster_id"', false)
            ->assertDontSee('Common Fund')
            ->assertDontSee('MDS-GAA')
            ->assertDontSee('Construction of Science Complex');
    }

    public function test_generation_can_be_limited_to_selected_employee_group(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $mainFund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();

        $regular = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'GROUP-REGULAR',
            'full_name' => 'Regular Payroll Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        $jobOrder = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'GROUP-JO',
            'full_name' => 'Job Order Payroll Employee',
            'employment_type' => 'Job Order',
            'monthly_salary' => 18000,
            'is_active' => true,
        ]);

        $this->mockHrisAttendance([
            'status' => 'success',
            'data' => [[
                'emp_ID' => 'GROUP-JO',
                'summary' => ['total_late_minutes' => 0, 'review_days' => 0],
                'daily' => [],
            ]],
        ]);

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'payroll_employee_type' => 'job_order',
            'remarks' => 'JO only generation',
            'signatories' => $this->signatoriesFor($campus),
        ])->assertRedirect();

        $batch = PayrollBatch::latest()->firstOrFail();

        $this->assertSame('job_order', $batch->snapshot['payroll_employee_type']);
        $this->assertSame('Job Order', $batch->snapshot['payroll_employee_type_label']);
        $this->assertEquals(1, $batch->total_employees);
        $this->assertDatabaseHas('payroll_lines', [
            'payroll_batch_id' => $batch->id,
            'employee_id' => $jobOrder->id,
            'employee_no' => 'GROUP-JO',
        ]);
        $this->assertDatabaseMissing('payroll_lines', [
            'payroll_batch_id' => $batch->id,
            'employee_id' => $regular->id,
        ]);
    }

    public function test_long_hris_runs_are_covered_by_a_blocking_overlay(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();

        // The HRIS employee sync is the slowest thing on the employees page.
        $this->actingAs($user)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee('data-process-overlay-trigger', false)
            ->assertSee('Syncing employees from HRIS', false)
            ->assertSee('data-process-overlay', false);

        // Payroll generation reads attendance for every employee in the run.
        $this->actingAs($user)
            ->get(route('payroll.index'))
            ->assertOk()
            ->assertSee('data-process-overlay-trigger', false)
            ->assertSee('Generating payroll', false)
            ->assertSee('Keep this tab open.', false);
    }
    public function test_employment_statuses_come_from_the_statuses_table(): void
    {
        $this->seed();

        // Fixed reference data - HRIS employees.emp_status stores these ids.
        $this->assertDatabaseHas('statuses', ['id' => 1, 'status_name' => 'Regular']);
        $this->assertDatabaseHas('statuses', ['id' => 2, 'status_name' => 'Full-time/Part-time']);
        $this->assertDatabaseHas('statuses', ['id' => 3, 'status_name' => 'Part-time/Part-time']);
        $this->assertDatabaseHas('statuses', ['id' => 4, 'status_name' => 'Job Order']);
        $this->assertSame(4, Status::count());

        // emp_status resolves as an id, and unknown values fall back to Regular.
        $this->assertSame(Status::JOB_ORDER, Status::resolveFromHrisStatus('4'));
        $this->assertSame(Status::PARTTIME_PARTTIME, Status::resolveFromHrisStatus(3));
        $this->assertSame(Status::REGULAR, Status::resolveFromHrisStatus(''));
        $this->assertSame(Status::REGULAR, Status::resolveFromHrisStatus('999'));

        // Labels shown anywhere in payroll are read from the table, not hardcoded.
        $options = app(PayrollEmployeeTypeService::class)->options();
        $this->assertSame('Job Order', $options['job_order']);
        $this->assertSame('Part-time/Part-time', $options['parttime_parttime']);

        Status::whereKey(Status::JOB_ORDER)->update(['status_name' => 'Job Order (COS)']);
        $this->assertSame('Job Order (COS)', app(PayrollEmployeeTypeService::class)->options()['job_order']);
    }

    public function test_employee_status_reads_and_writes_through_the_statuses_table(): void
    {
        $this->seed();

        $campus = Campus::where('code', 'Main')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'STATUS-001',
            'full_name' => 'Status Employee',
            'employment_type' => 'Job Order',
            'monthly_salary' => 18000,
            'is_active' => true,
        ]);

        // Written as a label, stored as the status id, read back as the label.
        $this->assertSame(Status::JOB_ORDER, (int) $employee->status_id);
        $this->assertSame('Job Order', $employee->employment_type);
        $this->assertTrue($employee->isJobOrder());
        $this->assertDatabaseHas('employees', ['employee_no' => 'STATUS-001', 'status_id' => Status::JOB_ORDER]);

        // A raw HRIS code works the same way.
        $employee->update(['employment_type' => '2']);
        $this->assertSame(Status::FULLTIME_PARTTIME, (int) $employee->refresh()->status_id);
        $this->assertSame('Full-time/Part-time', $employee->employment_type);
        $this->assertFalse($employee->isJobOrder());
    }
    public function test_daily_rate_divisor_follows_employment_type_not_the_fund_template(): void
    {
        $this->seed();

        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $computation = app(PayrollComputationService::class);

        // August 2026 has 21 weekdays; the month drives the divisor, not the period.
        $workingDays = $computation->workingDaysInMonth($period);
        $expected = 0;
        $cursor = $period->date_from->copy()->startOfMonth();

        while ($cursor->lte($period->date_from->copy()->endOfMonth())) {
            if (! $cursor->isWeekend()) {
                $expected++;
            }

            $cursor = $cursor->addDay();
        }

        $this->assertSame($expected, $workingDays);

        $make = fn (string $no, string $type) => Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => $no,
            'full_name' => 'Divisor '.$no,
            'employment_type' => $type,
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        // Job Order is always over 22 days, even when the month has more.
        $this->assertSame(22, $computation->dailyRateDivisor($make('DIV-JO', 'Job Order'), $period));

        // Everything else divides by the month's actual working days.
        $this->assertSame($workingDays, $computation->dailyRateDivisor($make('DIV-REG', 'Regular'), $period));
        $this->assertSame($workingDays, $computation->dailyRateDivisor($make('DIV-FTPT', 'Full-time/Part-time'), $period));
    }

    public function test_job_order_daily_rate_stays_on_twenty_two_days_in_a_longer_month(): void
    {
        $this->seed();

        $campus = Campus::where('code', 'Main')->firstOrFail();
        $computation = app(PayrollComputationService::class);

        // December 2026 has 23 weekdays.
        $period = PayrollPeriod::create([
            'name' => 'December 1-15, 2026',
            'date_from' => '2026-12-01',
            'date_to' => '2026-12-15',
            'period_type' => 'semi-monthly',
        ]);

        $this->assertSame(23, $computation->workingDaysInMonth($period));

        $jobOrder = Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'DIV-JO-DEC',
            'full_name' => 'Job Order December',
            'employment_type' => 'Job Order',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        $regular = Employee::create([
            'campus_id' => $campus->id,
            'employee_no' => 'DIV-REG-DEC',
            'full_name' => 'Regular December',
            'employment_type' => 'Regular',
            'monthly_salary' => 23000,
            'is_active' => true,
        ]);

        $this->assertSame(22, $computation->dailyRateDivisor($jobOrder, $period));
        $this->assertSame(23, $computation->dailyRateDivisor($regular, $period));

        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $attendance = app(AttendanceSummaryService::class)->summaryFor($jobOrder, $period);

        $jobOrderLine = $computation->computeLine($jobOrder, $period, $template, $attendance);
        $regularLine = $computation->computeLine($regular, $period, $template, $attendance);

        $this->assertEquals(1000.00, $jobOrderLine['rate_per_day']);
        $this->assertEquals(1000.00, $regularLine['rate_per_day']);
        $this->assertSame(22, $jobOrderLine['computed_columns']['working_days']);
        $this->assertSame(23, $regularLine['computed_columns']['working_days']);
    }
    public function test_offices_are_seeded_and_hris_department_ids_resolve_to_office_names(): void
    {
        $this->seed();

        // Reference data keeps its original ids because HRIS emp_dept points at them.
        $this->assertDatabaseHas('offices', ['id' => 28, 'office_name' => "PRESIDENT'S OFFICE"]);
        $this->assertDatabaseHas('offices', ['id' => 73, 'office_name' => 'MOISES PADILLA CAMPUS']);

        // emp_dept is an id, so it must resolve to the office rather than create one.
        $this->assertSame(73, Office::resolveFromHrisDepartment('73'));
        $this->assertSame(28, Office::resolveFromHrisDepartment(28));
        $this->assertNull(Office::resolveFromHrisDepartment(''));
        $this->assertNull(Office::resolveFromHrisDepartment('999999'));
        $this->assertDatabaseMissing('offices', ['office_name' => '73']);

        // Campuses and colleges carry stat = 2 but are still selectable.
        $this->assertTrue(Office::active()->whereKey(73)->exists());
        $this->assertSame(70, Office::count());
    }

    public function test_hris_sync_links_employees_to_offices_by_department_id(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();

        $this->mock(HrisDatabaseService::class, function (MockInterface $mock) use ($campus) {
            $mock->shouldReceive('employees')->once()->andReturn([
                'status' => 'connected',
                'data' => ['data' => [[
                    'emp_ID' => 'DEPT-001',
                    'name' => 'Department Linked Employee',
                    'position' => 'Instructor I',
                    'emp_dept' => '73',
                    'emp_status' => '1',
                    'camp_id' => $campus->id,
                ]]],
            ]);
        });

        $this->actingAs($user)->post(route('employees.sync-hris'))->assertRedirect();

        $employee = Employee::where('employee_no', 'DEPT-001')->firstOrFail();

        $this->assertSame(73, $employee->office_id);
        $this->assertSame('MOISES PADILLA CAMPUS', $employee->office_name);
        $this->assertDatabaseMissing('offices', ['office_name' => '73']);
    }
    public function test_part_time_rate_can_be_set_from_the_employees_list(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $partTimeFund = FundCluster::where('code', 'PT')->where('fund_source_name', 'PT')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'PT-SET-001',
            'full_name' => 'Dual Post Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 30000,
            'is_active' => true,
        ]);

        $this->assertFalse($employee->hasPartTimeAssignment());

        $this->actingAs($user)
            ->put(route('employees.part-time.update', $employee), [
                'part_time_rate_per_hour' => 185.50,
                'part_time_fund_cluster_id' => $partTimeFund->id,
            ])
            ->assertRedirect();

        $employee->refresh();

        $this->assertTrue($employee->hasPartTimeAssignment());
        $this->assertEquals(185.50, (float) $employee->part_time_rate_per_hour);
        $this->assertSame($partTimeFund->id, $employee->part_time_fund_cluster_id);

        // Clearing the rate takes the employee back off the part-time payroll.
        $this->actingAs($user)
            ->put(route('employees.part-time.update', $employee), [
                'part_time_rate_per_hour' => 0,
                'part_time_fund_cluster_id' => null,
            ])
            ->assertRedirect();

        $this->assertFalse($employee->refresh()->hasPartTimeAssignment());
    }

    public function test_part_time_fund_cluster_defaults_to_the_employee_fund_cluster(): void
    {
        $this->seed();

        $campus = Campus::where('code', 'Main')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();

        $employee = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'PT-DEFAULT-001',
            'full_name' => 'Default Fund Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 30000,
            'part_time_rate_per_hour' => 120,
            'is_active' => true,
        ]);

        $this->assertSame($employeeFund->id, $employee->partTimeFundClusterOrDefault()?->id);
    }

    public function test_part_time_rate_puts_a_regular_employee_on_the_part_time_payroll(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $employeeFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();
        $partTimeFund = FundCluster::where('code', 'PT')->where('fund_source_name', 'PT')->firstOrFail();

        // Regular employee who also holds a part-time post - one record, not two.
        $dualPost = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'PT-DUAL-001',
            'full_name' => 'Dual Post Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 30000,
            'part_time_rate_per_hour' => 200,
            'part_time_fund_cluster_id' => $partTimeFund->id,
            'is_active' => true,
        ]);

        $regularOnly = Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $employeeFund->id,
            'employee_no' => 'PT-REGULAR-001',
            'full_name' => 'Regular Only Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 28000,
            'is_active' => true,
        ]);

        $this->mockHrisAttendance(['status' => 'success', 'data' => []]);

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'payroll_employee_type' => 'parttime_parttime',
            'remarks' => 'Part-time generation',
            'signatories' => $this->signatoriesFor($campus),
        ])->assertRedirect();

        $line = PayrollLine::where('employee_id', $dualPost->id)->firstOrFail();
        $batch = PayrollBatch::findOrFail($line->payroll_batch_id);

        // Charged to the chosen part-time fund, not the employee's regular fund.
        $this->assertSame($partTimeFund->id, $batch->fund_cluster_id);
        $this->assertTrue($line->computed_columns['part_time']);
        $this->assertEquals(200.0, (float) $line->rate_per_hour);
        $this->assertEquals(0.0, (float) $line->monthly_salary);

        // Paid hourly for the hours actually rendered.
        $template = $batch->template;
        $expectedHours = $line->rendered_days * $template->hours_per_day;
        $this->assertEquals(round(200 * $expectedHours, 2), (float) $line->gross_income);
        $this->assertEquals($expectedHours, $line->computed_columns['hours_rendered']);

        // Statutory deductions stay on the regular payroll line only.
        $this->assertEquals(0.0, (float) $line->tax_amount);
        $this->assertEquals(0.0, (float) $line->sss);
        $this->assertEquals(0.0, (float) $line->philhealth);
        $this->assertEquals(0.0, (float) $line->pagibig);
        $this->assertEquals(0.0, (float) $line->total_deduction);
        $this->assertEquals((float) $line->earned_for_period, (float) $line->net_amount_received);
        $this->assertStringContainsString('Part-time', (string) $line->remarks);

        // A regular employee with no part-time rate is not pulled into this run.
        $this->assertDatabaseMissing('payroll_lines', ['employee_id' => $regularOnly->id]);

        // And the employee is only paid once in the run.
        $this->assertSame(1, PayrollLine::where('employee_id', $dualPost->id)->count());
    }
    public function test_one_generation_run_creates_a_draft_for_every_fund_with_employees(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();

        $funds = [
            'FAN-GAA-001' => FundCluster::where('fund_source_name', 'GAA')->firstOrFail(),
            'FAN-INC-001' => FundCluster::where('fund_source_name', 'Common Fund')->firstOrFail(),
            'FAN-PROJ-001' => FundCluster::where('payroll_template_type', 'PROJ')->whereColumn('code', '!=', 'payroll_template_type')->firstOrFail(),
            'FAN-NONE-001' => null,
        ];

        foreach ($funds as $employeeNo => $fund) {
            Employee::create([
                'campus_id' => $campus->id,
                'fund_cluster_id' => $fund?->id,
                'employee_no' => $employeeNo,
                'full_name' => 'Fan Out '.$employeeNo,
                'employment_type' => 'regular',
                'monthly_salary' => 22000,
                'is_active' => true,
            ]);
        }

        $this->mockHrisAttendance(['status' => 'success', 'data' => []]);

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'Fan out generation',
            'signatories' => $this->signatoriesFor($campus),
        ])->assertRedirect(route('payroll.index', ['campus' => $campus->id]));

        $batches = PayrollBatch::with(['fundCluster', 'template'])->get();

        // The signatory fixtures sit on INC, so this campus has employees on three funds.
        $this->assertEqualsCanonicalizing(
            ['MDS', 'INC', 'PROJ'],
            $batches->map(fn (PayrollBatch $batch) => $batch->fundCluster->payroll_template_type)->all()
        );
        $batches->each(fn (PayrollBatch $batch) => $this->assertSame(
            $batch->fundCluster->payroll_template_type,
            $batch->template->template_type
        ));

        // Every employee is paid exactly once across the whole run.
        $lines = PayrollLine::whereIn('payroll_batch_id', $batches->modelKeys())->get();
        $this->assertSame($lines->count(), $lines->unique('employee_id')->count());
        $this->assertSame(Employee::where('campus_id', $campus->id)->count(), $lines->count());

        // An employee with no fund cluster still gets paid, on the fallback fund, flagged.
        $fallback = $this->latestBatchFor(Employee::where('employee_no', 'FAN-NONE-001')->firstOrFail());
        $this->assertSame(PayrollFundTypeService::FALLBACK_TYPE, $fallback->fundCluster->payroll_template_type);
        $this->assertSame(1, $fallback->employees_with_missing_fund_source);

        // The drafts of one run share a reference so they can be traced together.
        $this->assertCount(1, $batches->pluck('snapshot.generation_run.ref')->unique());
    }

    public function test_payroll_draft_shows_workbook_style_fund_tabs_for_the_whole_run(): void
    {
        $this->seed();

        $user = User::where('email', 'super@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $gaaFund = FundCluster::where('fund_source_name', 'GAA')->firstOrFail();

        Employee::create([
            'campus_id' => $campus->id,
            'fund_cluster_id' => $gaaFund->id,
            'employee_no' => 'TAB-MDS-001',
            'full_name' => 'Tab Strip Employee',
            'employment_type' => 'regular',
            'monthly_salary' => 22000,
            'is_active' => true,
        ]);

        $this->mockHrisAttendance(['status' => 'success', 'data' => []]);

        $this->actingAs($user)->post(route('payroll.store'), [
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'remarks' => 'Fund tab test',
            'signatories' => $this->signatoriesFor($campus),
        ])->assertRedirect();

        // The signatory fixtures sit on INC, so the run produced an INC draft alongside MDS.
        $mds = $this->latestBatchFor(Employee::where('employee_no', 'TAB-MDS-001')->firstOrFail());
        $inc = PayrollBatch::whereKeyNot($mds->id)->firstOrFail();

        $html = $this->actingAs($user)->get(route('payroll.show', $mds))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<nav class="fund-tabs".*?<\/nav>/s', $html, $matches));
        $strip = $matches[0];

        // Every workbook tab is present, in sheet order.
        $positions = collect(PayrollFundTypeService::TYPES)->map(fn (string $type) => strpos($strip, $type));
        $positions->each(fn ($position, $index) => $this->assertNotFalse(
            $position,
            PayrollFundTypeService::TYPES[$index].' tab is missing from the fund strip.'
        ));
        $this->assertSame($positions->sort()->values()->all(), $positions->values()->all());

        // Blade directives must be compiled, not printed as text into the markup.
        $this->assertStringNotContainsString('@if', $strip);
        $this->assertStringNotContainsString('@endif', $strip);

        // The fund being viewed is the active tab; its sibling is one click away.
        $this->assertStringContainsString('href="'.route('payroll.show', $inc).'"', $strip);
        $this->assertStringContainsString('href="'.route('payroll.show', $mds).'"', $strip);
        $this->assertSame(1, substr_count($strip, 'aria-current="page"'));
        $this->assertSame(1, substr_count($strip, 'fund-tab active'));

        // Funds with nobody on them have no draft to open.
        $this->assertStringContainsString('No YEARBOOK employees in this run', $strip);
        $this->assertSame(5, substr_count($strip, 'fund-tab empty'));
    }

    public function test_campus_admin_generate_payroll_prefills_previous_signatories_with_searchable_selects(): void
    {
        $this->seed();

        $user = User::where('email', 'main.payroll@cpsu.edu.ph')->firstOrFail();
        $campus = Campus::where('code', 'Main')->firstOrFail();
        $period = PayrollPeriod::where('name', 'August 1-15, 2026')->firstOrFail();
        $template = PayrollTemplate::where('code', 'MDS')->firstOrFail();
        $fund = FundCluster::where('code', 'MDS')->where('fund_source_name', 'MDS')->firstOrFail();
        $signatories = $this->signatoriesFor($campus);

        PayrollBatch::create([
            'campus_id' => $campus->id,
            'payroll_period_id' => $period->id,
            'payroll_template_id' => $template->id,
            'fund_cluster_id' => $fund->id,
            'created_by' => $user->id,
            'batch_no' => 'TEST-PREVIOUS-SIGNATORIES',
            'status' => PayrollBatch::DRAFT,
            'snapshot' => [
                'signatories' => app(PayrollSignatoryService::class)->snapshot($signatories),
            ],
        ]);

        $this->actingAs($user)
            ->get(route('payroll.create'))
            ->assertOk()
            ->assertSee('name="campus_id" data-searchable-select', false)
            ->assertSee('name="payroll_period_id" data-searchable-select', false)
            ->assertDontSee('name="payroll_template_id" data-searchable-select', false)
            ->assertDontSee('name="fund_cluster_id"', false)
            ->assertSee('data-employee-search', false)
            ->assertSee('name="signatories[prepared_by]"', false)
            ->assertSee('value="'.$signatories['prepared_by'].'" selected', false)
            ->assertSee('value="'.$signatories['certified_correct_by'].'" selected', false)
            ->assertSee('value="'.$signatories['approved_by'].'" selected', false)
            ->assertSee('value="'.$signatories['certified_payment_by'].'" selected', false);
    }

    private function mockHrisAttendance(array $payload): void
    {
        $this->mock(HrisAttendanceDatabaseService::class, function (MockInterface $mock) use ($payload) {
            $mock->shouldReceive('tardiness')->andReturn([
                'status' => 'connected',
                'data' => $payload,
            ]);
        });
    }

    private function latestBatchFor(Employee $employee): PayrollBatch
    {
        // A run now creates one batch per fund, so find the one this employee landed on.
        return PayrollBatch::query()
            ->whereHas('lines', fn ($query) => $query->where('employee_id', $employee->id))
            ->latest('id')
            ->firstOrFail();
    }

    private function signatoriesFor(Campus $campus): array
    {
        $fund = FundCluster::where('fund_source_name', 'Common Fund')->firstOrFail();

        return collect(PayrollSignatoryService::ROLES)
            ->keys()
            ->mapWithKeys(function (string $role, int $index) use ($campus, $fund) {
                $employee = Employee::updateOrCreate(
                    ['employee_no' => 'AUTO-SIGN-'.$campus->id.'-'.$index],
                    [
                        'campus_id' => $campus->id,
                        'fund_cluster_id' => $fund->id,
                        'full_name' => 'Auto Signatory '.($index + 1),
                        'designation' => match ($role) {
                            'prepared_by' => 'Payroll Officer',
                            'certified_correct_by' => 'Accountant III',
                            'approved_by' => 'SUC President II',
                            default => 'Cashier III',
                        },
                        'employment_type' => 'regular',
                        'is_active' => true,
                    ]
                );

                return [$role => $employee->id];
            })
            ->all();
    }
}
