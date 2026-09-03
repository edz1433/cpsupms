<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employment status is reference data, not a free-text column. HRIS stores it as
     * employees.emp_status, which is the id of the row in this table, so the ids below
     * are fixed and must not be renumbered.
     */
    public const ROWS = [
        [1, 'Regular'],
        [2, 'Full-time/Part-time'],
        [3, 'Part-time/Part-time'],
        [4, 'Job Order'],
    ];

    /**
     * Every spelling that has appeared in the employment_type column or in HRIS,
     * lowercased, mapped to the status it means.
     */
    private const ALIASES = [
        1 => ['1', 'regular', 'permanent', 'full time', 'full-time', 'fulltime', 'plantilla'],
        2 => ['2', 'full-time/part-time', 'fulltime/parttime', 'full-time part-time', 'fulltime parttime', 'fulltime-parttime', 'full-time/parttime', 'fulltime/part-time'],
        3 => ['3', 'part-time/part-time', 'parttime/parttime', 'part-time part-time', 'parttime parttime', 'parttime-parttime', 'partime/partime', 'part-time/part-tiem'],
        4 => ['4', 'job order', 'job-order', 'jo', 'j.o.', 'contractual', 'contract of service', 'cos'],
    ];

    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('status_name');
            $table->string('qualifications')->default('');
            $table->timestamps();
        });

        $this->seedStatuses();

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('employment_type')->constrained()->nullOnDelete();
        });

        $this->backfillStatuses();

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_type')->default('Regular')->after('designation');
        });

        foreach (DB::table('statuses')->get() as $status) {
            DB::table('employees')
                ->where('status_id', $status->id)
                ->update(['employment_type' => $status->status_name]);
        }

        Schema::table('employees', function (Blueprint $table) {
            // SQLite has no DROP FOREIGN KEY; dropping the column is enough there.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['status_id']);
            }

            $table->dropColumn('status_id');
        });

        Schema::dropIfExists('statuses');
    }

    private function seedStatuses(): void
    {
        $now = now();

        foreach (self::ROWS as [$id, $name]) {
            DB::table('statuses')->updateOrInsert(
                ['id' => $id],
                ['status_name' => $name, 'qualifications' => '', 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    private function backfillStatuses(): void
    {
        $lookup = [];

        foreach (self::ALIASES as $id => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[$alias] = $id;
            }
        }

        foreach (self::ROWS as [$id, $name]) {
            $lookup[strtolower($name)] = $id;
        }

        $values = DB::table('employees')
            ->whereNotNull('employment_type')
            ->distinct()
            ->pluck('employment_type');

        foreach ($values as $value) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '\\'], ['-', '/'], (string) $value))));

            // Anything unrecognised is Regular, matching how payroll already read it.
            DB::table('employees')
                ->where('employment_type', $value)
                ->update(['status_id' => $lookup[$key] ?? 1]);
        }

        DB::table('employees')->whereNull('status_id')->update(['status_id' => 1]);
    }
};
