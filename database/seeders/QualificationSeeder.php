<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QualificationSeeder extends Seeder
{
    /**
     * Inserted with their original ids so the values stay stable for anything that
     * references a qualification by id.
     */
    public const ROWS = [
        [1, 'MA/PhD'],
        [2, 'CAR'],
        [3, 'License'],
        [4, 'MA unit'],
    ];

    public function run(): void
    {
        foreach (self::ROWS as [$id, $qualification]) {
            DB::table('qualifications')->updateOrInsert(
                ['id' => $id],
                ['qualification' => $qualification],
            );
        }
    }
}
