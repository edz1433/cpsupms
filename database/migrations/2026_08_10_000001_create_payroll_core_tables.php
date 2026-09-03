<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fund_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('payroll_template_type');
            $table->string('fund_source_name');
            $table->json('default_signatories')->nullable();
            $table->json('default_deduction_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['code', 'campus_id']);
        });

        Schema::create('payroll_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('template_type');
            $table->unsignedInteger('working_days')->default(22);
            $table->unsignedInteger('hours_per_day')->default(8);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payroll_template_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fund_cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->string('column_key');
            $table->string('display_label');
            $table->string('column_group');
            $table->string('type');
            $table->string('direction')->default('neutral');
            $table->text('formula_expression')->nullable();
            $table->boolean('manual_input_allowed')->default(false);
            $table->decimal('default_value', 12, 2)->default(0);
            $table->boolean('is_required')->default(false);
            $table->boolean('show_in_draft')->default(true);
            $table->boolean('show_in_final')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('width')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fund_cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_no')->unique();
            $table->string('full_name');
            $table->string('office')->nullable();
            $table->string('designation')->nullable();
            $table->string('employment_type')->default('regular');
            $table->string('salary_grade')->nullable();
            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->decimal('rate_per_day', 12, 2)->default(0);
            $table->decimal('rate_per_hour', 12, 2)->default(0);
            $table->decimal('rate_per_minute', 12, 4)->default(0);
            $table->decimal('tax_rate', 5, 4)->default(0.00);
            $table->string('tax_status')->nullable();
            $table->string('bir_sworn_status')->nullable();
            $table->decimal('sss_amount', 12, 2)->default(0);
            $table->decimal('philhealth_amount', 12, 2)->default(0);
            $table->decimal('pagibig_amount', 12, 2)->default(0);
            $table->decimal('nsca_mpc_amount', 12, 2)->default(0);
            $table->decimal('other_deductions_amount', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('date_from');
            $table->date('date_to');
            $table->string('period_type')->default('semi-monthly');
            $table->string('payroll_type')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });

        Schema::create('attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->decimal('present_days', 8, 2)->default(0);
            $table->decimal('absent_days', 8, 2)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->string('missing_log_status')->default('No issue');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'payroll_period_id']);
        });

        Schema::create('missing_log_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->date('attendance_date');
            $table->string('missing_log_status');
            $table->string('status')->default('under_review');
            $table->decimal('credited_days', 8, 2)->default(0);
            $table->text('reason')->nullable();
            $table->text('review_remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fund_cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('current_reviewer_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->string('batch_no')->unique();
            $table->string('status')->default('Draft');
            $table->unsignedInteger('total_employees')->default(0);
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->unsignedInteger('employees_with_missing_logs')->default(0);
            $table->unsignedInteger('employees_with_approved_appeals')->default(0);
            $table->unsignedInteger('employees_with_unresolved_appeals')->default(0);
            $table->unsignedInteger('employees_with_manual_adjustments')->default(0);
            $table->unsignedInteger('employees_with_missing_fund_source')->default(0);
            $table->unsignedInteger('employees_with_negative_net')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('printed_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('employee_no');
            $table->string('employee_name');
            $table->string('designation')->nullable();
            $table->string('fund_source')->nullable();
            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->decimal('rendered_days', 8, 2)->default(0);
            $table->decimal('absent_days', 8, 2)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->decimal('rate_per_day', 12, 2)->default(0);
            $table->decimal('rate_per_hour', 12, 2)->default(0);
            $table->decimal('rate_per_minute', 12, 4)->default(0);
            $table->decimal('gross_income', 12, 2)->default(0);
            $table->decimal('late_deduction', 12, 2)->default(0);
            $table->decimal('undertime_deduction', 12, 2)->default(0);
            $table->decimal('absent_deduction', 12, 2)->default(0);
            $table->decimal('salary_differential', 12, 2)->default(0);
            $table->decimal('earned_for_period', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('sss', 12, 2)->default(0);
            $table->decimal('philhealth', 12, 2)->default(0);
            $table->decimal('pagibig', 12, 2)->default(0);
            $table->decimal('nsca_mpc', 12, 2)->default(0);
            $table->decimal('project_deduction', 12, 2)->default(0);
            $table->decimal('graduate_school_deduction', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('total_deduction', 12, 2)->default(0);
            $table->decimal('net_amount_received', 12, 2)->default(0);
            $table->string('missing_log_status')->default('No issue');
            $table->string('appeal_status')->nullable();
            $table->boolean('has_manual_adjustment')->default(false);
            $table->text('remarks')->nullable();
            $table->json('computed_columns')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->references('id')->on('users')->cascadeOnDelete();
            $table->string('action');
            $table->text('remarks');
            $table->timestamps();
        });

        Schema::create('payroll_print_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('printed_by')->references('id')->on('users')->cascadeOnDelete();
            $table->string('purpose')->default('final_print');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('hris_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->string('request_type');
            $table->string('status');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->json('payload_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('remarks')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('hris_sync_logs');
        Schema::dropIfExists('payroll_print_logs');
        Schema::dropIfExists('payroll_reviews');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_batches');
        Schema::dropIfExists('missing_log_appeals');
        Schema::dropIfExists('attendance_summaries');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('payroll_template_columns');
        Schema::dropIfExists('payroll_templates');
        Schema::dropIfExists('fund_clusters');
        Schema::dropIfExists('campuses');
        Schema::dropIfExists('roles');
    }
};
