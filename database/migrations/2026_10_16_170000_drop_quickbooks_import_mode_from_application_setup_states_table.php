<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The first-run Customer/Site import source is chosen by the action taken
     * (a QuickBooks file import or a FieldPulse document upload), not a stored
     * mode, so the API-vs-CSV selector column is removed.
     */
    public function up(): void
    {
        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->dropColumn('quickbooks_import_mode');
        });
    }

    public function down(): void
    {
        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->string('quickbooks_import_mode', 20)->default('quickbooks_api');
        });
    }
};
