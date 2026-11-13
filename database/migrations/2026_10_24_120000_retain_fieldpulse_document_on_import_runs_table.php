<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retain the uploaded FieldPulse document.
 *
 * The first-run FieldPulse import previously deleted its stored workbook after a
 * successful import. That workbook is the migration's source of record (it is
 * the only place the aggregated FieldPulse data exists once the provider is
 * gone), so it is now retained and its identity recorded on the run.
 *
 * See ADR-0042 (amended) and ADR-0043.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->string('document_path', 191)->nullable()->after('customer_sites_completed_at');
            $table->char('document_sha256', 64)->nullable()->after('document_path');
            $table->unsignedBigInteger('document_bytes')->nullable()->after('document_sha256');
            $table->timestampTz('document_retained_at')->nullable()->after('document_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->dropColumn(['document_path', 'document_sha256', 'document_bytes', 'document_retained_at']);
        });
    }
};
