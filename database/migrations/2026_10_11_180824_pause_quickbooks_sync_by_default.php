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
            $table->boolean('quickbooks_sync_enabled')->default(false)->change();
        });

        DB::table('application_setup_states')
            ->where('quickbooks_sync_enabled', true)
            ->update(['quickbooks_sync_enabled' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve the paused state on rollback; re-enabling still requires the Admin password.
        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->boolean('quickbooks_sync_enabled')->default(true)->change();
        });
    }
};
