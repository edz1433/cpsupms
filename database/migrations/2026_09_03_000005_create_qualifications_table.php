<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qualification reference data. Ids are fixed so anything already storing a
     * qualification id keeps pointing at the same row.
     */
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('qualification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifications');
    }
};
