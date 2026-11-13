<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20)->unique();
            $table->string('status', 32)->default('in_progress');
            $table->string('stage', 32)->default('customers');
            $table->unsignedInteger('customers_page')->default(1);
            $table->unsignedInteger('customers_total')->default(0);
            $table->unsignedInteger('customers_imported')->default(0);
            $table->unsignedInteger('sites_imported')->default(0);
            $table->unsignedInteger('contacts_imported')->default(0);
            $table->string('failure_code', 80)->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('customer_sites_completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('fieldpulse_import_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('fieldpulse_import_runs')->cascadeOnDelete();
            $table->string('resource_type', 20);
            $table->string('source_record_id', 255);
            $table->string('failure_code', 80);
            $table->timestampsTz();
            $table->unique(['import_id', 'resource_type', 'source_record_id'], 'fieldpulse_import_failure_unique');
        });

        // Stable FieldPulse provider identifiers, separate from QuickBooks and CSV IDs.
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('fieldpulse_id', 64)->nullable()->unique();
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->string('fieldpulse_id', 64)->nullable()->unique();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('fieldpulse_id', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (DB::table('customers')->whereNotNull('fieldpulse_id')->exists()
            || DB::table('sites')->whereNotNull('fieldpulse_id')->exists()
            || DB::table('contacts')->whereNotNull('fieldpulse_id')->exists()) {
            throw new RuntimeException('Cannot roll back FieldPulse import support while imported provider IDs exist.');
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['fieldpulse_id']);
            $table->dropColumn('fieldpulse_id');
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique(['fieldpulse_id']);
            $table->dropColumn('fieldpulse_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['fieldpulse_id']);
            $table->dropColumn('fieldpulse_id');
        });

        Schema::dropIfExists('fieldpulse_import_failures');
        Schema::dropIfExists('fieldpulse_import_runs');
    }
};
