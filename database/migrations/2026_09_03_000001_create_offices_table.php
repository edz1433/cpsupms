<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('office_name');
            $table->string('office_abbr');
            $table->string('office_head_id')->nullable();
            $table->integer('oic_id')->nullable();
            $table->string('orders')->nullable();
            $table->integer('group_by')->default(0);
            $table->integer('stat')->default(1);
            $table->timestamps();

            $table->index('office_name');
            $table->index('stat');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('fund_cluster_id')->constrained()->nullOnDelete();
        });

        $this->backfillOffices();

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('office');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('office')->nullable()->after('fund_cluster_id');
        });

        foreach (DB::table('offices')->get() as $office) {
            DB::table('employees')
                ->where('office_id', $office->id)
                ->update(['office' => $office->office_name]);
        }

        Schema::table('employees', function (Blueprint $table) {
            // SQLite has no DROP FOREIGN KEY; dropping the column is enough there.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['office_id']);
            }

            $table->dropColumn('office_id');
        });

        Schema::dropIfExists('offices');
    }

    /**
     * Promote every distinct free-text employee office into an offices row,
     * then point the employee at it so no existing assignment is lost.
     */
    private function backfillOffices(): void
    {
        $names = DB::table('employees')
            ->whereNotNull('office')
            ->where('office', '<>', '')
            ->distinct()
            ->orderBy('office')
            ->pluck('office');

        $now = now();

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $officeId = DB::table('offices')->where('office_name', $name)->value('id')
                ?? DB::table('offices')->insertGetId([
                    'office_name' => $name,
                    'office_abbr' => $this->abbreviate($name),
                    'group_by' => 0,
                    'stat' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('employees')->where('office', $name)->update(['office_id' => $officeId]);
        }
    }

    private function abbreviate(string $name): string
    {
        $noise = ['of', 'and', 'for', 'the', 'in', 'on', 'to', 'a'];
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';

        foreach ($words as $word) {
            if (in_array(strtolower($word), $noise, true)) {
                continue;
            }

            $letters .= strtoupper($word[0]);
        }

        return substr($letters !== '' ? $letters : strtoupper($name), 0, 20);
    }
};
