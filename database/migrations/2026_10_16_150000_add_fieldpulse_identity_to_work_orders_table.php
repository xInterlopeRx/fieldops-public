<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('fieldpulse_id', 64)->nullable()->unique();
        });

        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('jobs_imported')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('work_orders')->whereNotNull('fieldpulse_id')->exists()) {
            throw new RuntimeException('Cannot roll back FieldPulse Job support while imported provider IDs exist.');
        }

        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->dropColumn('jobs_imported');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropUnique(['fieldpulse_id']);
            $table->dropColumn('fieldpulse_id');
        });
    }
};
