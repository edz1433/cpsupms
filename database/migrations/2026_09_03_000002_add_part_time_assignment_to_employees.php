<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee who also works part-time used to be entered twice under the same
     * name. Instead the single record now carries a part-time hourly rate; any rate
     * above zero means the person is also paid on the Part-time/Part-time payroll.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('part_time_rate_per_hour', 12, 2)->default(0)->after('rate_per_minute');
            $table->foreignId('part_time_fund_cluster_id')->nullable()->after('part_time_rate_per_hour')->constrained('fund_clusters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // SQLite has no DROP FOREIGN KEY; dropping the columns is enough there.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['part_time_fund_cluster_id']);
            }

            $table->dropColumn(['part_time_fund_cluster_id', 'part_time_rate_per_hour']);
        });
    }
};
