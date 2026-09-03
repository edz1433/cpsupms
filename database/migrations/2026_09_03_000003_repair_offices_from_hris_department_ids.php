<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The first offices migration promoted employees.office free text into office names.
     * That text is really the HRIS emp_dept id, so it produced offices literally named
     * "73". This replaces those placeholders with the real office reference data and
     * re-points each employee at the office its department id refers to.
     */
    public function up(): void
    {
        // Placeholder offices are the ones whose name is just a department id.
        $departmentByOffice = DB::table('offices')
            ->pluck('office_name', 'id')
            ->filter(fn ($name) => ctype_digit(trim((string) $name)))
            ->map(fn ($name) => (int) trim((string) $name));

        if ($departmentByOffice->isNotEmpty()) {
            $departmentByEmployee = DB::table('employees')
                ->whereIn('office_id', $departmentByOffice->keys()->all())
                ->pluck('office_id', 'id')
                ->map(fn ($officeId) => $departmentByOffice->get($officeId));

            // Detach first so the placeholder ids are free for the real offices.
            DB::table('employees')
                ->whereIn('office_id', $departmentByOffice->keys()->all())
                ->update(['office_id' => null]);

            DB::table('offices')->whereIn('id', $departmentByOffice->keys()->all())->delete();
        } else {
            $departmentByEmployee = collect();
        }

        $this->seedOffices();

        $known = DB::table('offices')->pluck('id')->flip();

        foreach ($departmentByEmployee as $employeeId => $departmentId) {
            if ($known->has($departmentId)) {
                DB::table('employees')->where('id', $employeeId)->update(['office_id' => $departmentId]);
            }
        }
    }

    public function down(): void
    {
        // Reference data only - the previous placeholder rows are not worth restoring.
    }

    private function seedOffices(): void
    {
        $now = now();

        foreach (require database_path('data/offices.php') as [$id, $name, $abbr, $headId, $oicId, $orders, $groupBy, $stat]) {
            DB::table('offices')->updateOrInsert(
                ['id' => $id],
                [
                    'office_name' => $name,
                    'office_abbr' => $abbr,
                    'office_head_id' => $headId,
                    'oic_id' => $oicId,
                    'orders' => $orders,
                    'group_by' => $groupBy,
                    'stat' => $stat,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
};
