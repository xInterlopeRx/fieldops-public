<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('fieldpulse_id', 64)->nullable()->unique();
            $table->jsonb('metadata')->nullable();
        });

        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('projects_imported')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('projects')->whereNotNull('fieldpulse_id')->exists()) {
            throw new RuntimeException('Cannot roll back FieldPulse Project support while imported Project IDs exist.');
        }

        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->dropColumn('projects_imported');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['fieldpulse_id']);
            $table->dropColumn(['fieldpulse_id', 'metadata']);
        });
    }
};
