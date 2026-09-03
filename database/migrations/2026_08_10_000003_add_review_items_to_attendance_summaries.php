<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_summaries', function (Blueprint $table) {
            $table->json('review_items')->nullable()->after('missing_log_status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_summaries', function (Blueprint $table) {
            $table->dropColumn('review_items');
        });
    }
};
