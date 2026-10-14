<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->boolean('quickbooks_sync_enabled')->default(true);
        });
    }

    public function down(): void
    {
        if (DB::table('application_setup_states')->where('quickbooks_sync_enabled', false)->exists()
            || (Schema::hasTable('jobs') && DB::table('jobs')->where('queue', 'like', 'quickbooks-paused:%')->exists())) {
            throw new RuntimeException('Cannot remove the QuickBooks sync control while it is paused or holding jobs. Resume QuickBooks sync first.');
        }

        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->dropColumn('quickbooks_sync_enabled');
        });
    }
};
