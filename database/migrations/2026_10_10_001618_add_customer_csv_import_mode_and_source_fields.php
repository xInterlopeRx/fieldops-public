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
        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->string('quickbooks_import_mode', 20)->default('quickbooks_api');
        });

        Schema::table('bitwarden_secret_manager_connections', function (Blueprint $table): void {
            $table->string('verification_profile', 20)->default('all_required');
        });

        Schema::table('quickbooks_initial_customer_imports', function (Blueprint $table): void {
            $table->string('source', 20)->default('quickbooks_api');
            $table->string('realm_id')->nullable()->change();
            $table->longText('source_payload')->nullable();
            $table->char('source_sha256', 64)->nullable();
        });

        Schema::table('quickbooks_initial_customer_import_failures', function (Blueprint $table): void {
            $table->string('quickbooks_customer_id', 64)->nullable()->change();
            $table->string('source_record_id', 255)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->unique(['import_id', 'source_record_id'], 'qbo_initial_import_source_record_unique');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('import_source', 20)->nullable();
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->string('import_source', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('application_setup_states')->where('quickbooks_import_mode', 'csv_upload')->exists()
            || DB::table('bitwarden_secret_manager_connections')->where('verification_profile', 'graph_only')->exists()
            || DB::table('customers')->where('import_source', 'csv_upload')->exists()
            || DB::table('sites')->where('import_source', 'csv_upload')->exists()
            || DB::table('quickbooks_initial_customer_imports')
                ->where(fn ($query) => $query->where('source', 'csv_upload')->orWhereNotNull('source_payload')->orWhereNull('realm_id'))
                ->exists()
            || DB::table('quickbooks_initial_customer_import_failures')->whereNotNull('source_record_id')->exists()) {
            throw new RuntimeException('Cannot roll back CSV import support while CSV-mode state or imported source data exists.');
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('import_source');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('import_source');
        });

        Schema::table('quickbooks_initial_customer_import_failures', function (Blueprint $table): void {
            $table->dropUnique('qbo_initial_import_source_record_unique');
            $table->dropColumn(['source_record_id', 'source_row']);
            $table->string('quickbooks_customer_id', 64)->nullable(false)->change();
        });

        Schema::table('quickbooks_initial_customer_imports', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_payload', 'source_sha256']);
            $table->string('realm_id')->nullable(false)->change();
        });

        Schema::table('bitwarden_secret_manager_connections', function (Blueprint $table): void {
            $table->dropColumn('verification_profile');
        });

        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->dropColumn('quickbooks_import_mode');
        });
    }
};
