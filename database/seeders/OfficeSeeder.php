<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OfficeSeeder extends Seeder
{
    /**
     * Office ids are referenced directly by HRIS employees.emp_dept, so rows are
     * inserted with their original ids rather than being renumbered.
     */
    public function run(): void
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
}
