<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\FundCluster;
use App\Models\PayrollPeriod;
use App\Models\PayrollTemplate;
use App\Models\PayrollTemplateColumn;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PayrollFundTypeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $roles = collect([
            'Super Administrator',
            'University Payroll Administrator',
            'Campus Payroll Administrator',
            'Auditor / Accounting Viewer',
        ])->mapWithKeys(fn ($name) => [
            $name => Role::updateOrCreate(
                ['slug' => str($name)->slug()->toString()],
                ['name' => $name]
            ),
        ]);

        $campuses = collect([
            ['id' => 1, 'name' => 'CPSU Main', 'code' => 'Main'],
            ['id' => 2, 'name' => 'CPSU Candoni', 'code' => 'Candoni'],
            ['id' => 3, 'name' => 'CPSU Cauayan', 'code' => 'Cauayan'],
            ['id' => 4, 'name' => 'CPSU Hinigaran', 'code' => 'Hinigaran'],
            ['id' => 5, 'name' => 'CPSU Hinoba-an', 'code' => 'Hinoba-an'],
            ['id' => 6, 'name' => 'CPSU Ilog', 'code' => 'Ilog'],
            ['id' => 7, 'name' => 'CPSU San Carlos', 'code' => 'San Carlos'],
            ['id' => 8, 'name' => 'CPSU Sipalay', 'code' => 'Sipalay'],
            ['id' => 9, 'name' => 'CPSU Victorias', 'code' => 'Victorias'],
            ['id' => 10, 'name' => 'CPSU Murcia', 'code' => 'Murcia'],
            ['id' => 11, 'name' => 'CPSU Valladolid', 'code' => 'Valladolid'],
            ['id' => 12, 'name' => 'CPSU Moises Padilla', 'code' => 'Moises Padilla'],
        ])->mapWithKeys(fn ($campus) => [
            $campus['code'] => Campus::updateOrCreate(['id' => $campus['id']], $campus + ['is_active' => true]),
        ]);

        User::updateOrCreate(['email' => 'super@cpsu.edu.ph'], [
            'role_id' => $roles['Super Administrator']->id,
            'campus_id' => null,
            'name' => 'Super Administrator',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        User::updateOrCreate(['email' => 'university.payroll@cpsu.edu.ph'], [
            'role_id' => $roles['University Payroll Administrator']->id,
            'campus_id' => null,
            'name' => 'University Payroll Administrator',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        foreach ($campuses as $campus) {
            $emailSlug = str($campus->code)->slug('-')->toString();

            User::updateOrCreate(['email' => $emailSlug.'.payroll@cpsu.edu.ph'], [
                'role_id' => $roles['Campus Payroll Administrator']->id,
                'campus_id' => $campus->id,
                'name' => $campus->code.' Payroll Administrator',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]);
        }

        User::updateOrCreate(['email' => 'auditor@cpsu.edu.ph'], [
            'role_id' => $roles['Auditor / Accounting Viewer']->id,
            'campus_id' => null,
            'name' => 'Accounting Viewer',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $templates = collect([
            ['code' => 'PT', 'name' => 'Part-time Payroll', 'template_type' => 'PT', 'working_days' => 22],
            ['code' => 'INC', 'name' => 'Income / Internally Generated Funds Payroll', 'template_type' => 'INC', 'working_days' => 22],
            ['code' => 'MDS', 'name' => 'GAA / MDS Payroll', 'template_type' => 'MDS', 'working_days' => 22],
            ['code' => 'PROJ', 'name' => 'Project Fund Payroll', 'template_type' => 'PROJ', 'working_days' => 23],
            ['code' => 'BUSTYPE', 'name' => 'Business-type Income Payroll', 'template_type' => 'BUSTYPE', 'working_days' => 22],
            ['code' => 'YEARBOOK', 'name' => 'Yearbook / Project-based Payroll', 'template_type' => 'YEARBOOK', 'working_days' => 22],
            ['code' => 'SUPPORT SERVICES', 'name' => 'Support Service Personnel Payroll', 'template_type' => 'SUPPORT SERVICES', 'working_days' => 22],
        ])->mapWithKeys(fn ($template) => [
            $template['code'] => PayrollTemplate::updateOrCreate(['code' => $template['code']], $template + ['hours_per_day' => 8, 'is_active' => true]),
        ]);

        $columns = [
            ['employee_name', 'Name', 'employee info', 'text', 'neutral'],
            ['designation', 'Designation', 'employee info', 'text', 'neutral'],
            ['monthly_salary', 'Monthly Salary', 'salary/addition', 'money', 'neutral'],
            ['rendered_days', 'Rendered Days', 'hidden computation', 'number', 'neutral'],
            ['gross_income', 'Gross Income', 'salary/addition', 'money', 'addition'],
            ['late_deduction', 'Late', 'attendance deduction', 'money', 'deduction'],
            ['undertime_deduction', 'Undertime', 'attendance deduction', 'money', 'deduction'],
            ['absent_deduction', 'Absent', 'attendance deduction', 'money', 'deduction'],
            ['earned_for_period', 'Earned', 'salary/addition', 'money', 'neutral'],
            ['tax_amount', 'Tax', 'statutory deduction', 'money', 'deduction'],
            ['sss', 'SSS', 'statutory deduction', 'money', 'deduction'],
            ['philhealth', 'PhilHealth', 'statutory deduction', 'money', 'deduction'],
            ['pagibig', 'Pag-IBIG', 'statutory deduction', 'money', 'deduction'],
            ['other_deductions', 'Other Deduction', 'other deduction', 'money', 'deduction'],
            ['net_amount_received', 'Net Amount Received', 'net pay', 'money', 'neutral'],
            ['remarks', 'Remarks', 'remarks', 'remarks', 'neutral'],
        ];

        foreach ($templates as $template) {
            foreach ($columns as $index => [$key, $label, $group, $type, $direction]) {
                PayrollTemplateColumn::updateOrCreate(
                    ['payroll_template_id' => $template->id, 'column_key' => $key],
                    [
                        'display_label' => $label,
                        'column_group' => $group,
                        'type' => $type,
                        'direction' => $direction,
                        'sort_order' => $index + 1,
                        'is_active' => true,
                    ]
                );
            }
        }

        $fundSources = [
            ['MDS', 'GAA'],
            ['INC', 'Common Fund'],
            ['INC', 'Allocation for Administrative'],
            ['INC', 'Allocation for Instruction'],
            ['INC', 'CAF Lab Fund'],
            ['INC', 'Graduate School-Tuition'],
            ['INC', 'Tuition-Production'],
            ['INC', 'Internet Fund'],
            ['INC', 'Medical-Dental Fund'],
            ['INC', 'Guidance Fee'],
            ['INC', 'Registration Fund'],
            ['PROJ', 'Construction of Grad School-Phase I Additional Works'],
            ['PROJ', 'Construction of Science Complex-Phase 2'],
            ['PROJ', 'Construction of Athlete\'s Housing'],
            ['BUSTYPE', 'CPSU Food Services'],
            ['BUSTYPE', 'Water System'],
            ['BUSTYPE', 'Income from Dormitory'],
            ['YEARBOOK', 'Project Based'],
            ['SUPPORT SERVICES', 'International Affairs and Linkages Office'],
        ];

        foreach ($fundSources as $index => [$type, $source]) {
            FundCluster::updateOrCreate(
                ['code' => $type.'-'.str($source)->slug('-')->limit(20, '')->upper()],
                [
                    'campus_id' => null,
                    'name' => $source.' Payroll',
                    'payroll_template_type' => $type,
                    'fund_source_name' => $source,
                    'default_signatories' => ['prepared_by' => 'Campus Payroll Administrator', 'checked_by' => 'University Payroll Administrator'],
                    'default_deduction_rules' => ['tax_configurable' => true],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ]
            );
        }
        app(PayrollFundTypeService::class)->ensureMainFundClusters();

        PayrollPeriod::updateOrCreate(
            ['name' => 'August 1-15, 2026'],
            [
                'campus_id' => null,
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-15',
                'period_type' => 'semi-monthly',
                'payroll_type' => null,
                'is_locked' => false,
            ]
        );
        PayrollPeriod::updateOrCreate(
            ['name' => 'August 16-31, 2026'],
            [
                'campus_id' => null,
                'date_from' => '2026-08-16',
                'date_to' => '2026-08-31',
                'period_type' => 'semi-monthly',
                'payroll_type' => null,
                'is_locked' => false,
            ]
        );

        SystemSetting::updateOrCreate(['key' => 'hris_stale_allowed_hours'], [
            'value' => '24',
            'type' => 'integer',
            'description' => 'Maximum age of required HRIS sync data before final approval requires override.',
        ]);
    }
}
