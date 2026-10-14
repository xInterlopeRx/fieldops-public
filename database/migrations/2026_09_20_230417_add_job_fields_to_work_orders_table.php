<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('job_number')->nullable()->unique()->after('id');
            $table->text('job_notes')->nullable()->after('description');
            $table->text('field_notes')->nullable()->after('job_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropUnique(['job_number']);
            $table->dropColumn(['job_number', 'job_notes', 'field_notes']);
        });
    }
};
