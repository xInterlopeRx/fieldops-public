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
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('quickbooks_sync_status', 20)->default('idle');
            $table->string('quickbooks_sync_error', 64)->nullable();
            $table->timestamp('quickbooks_sync_requested_at')->nullable();
            $table->timestamp('quickbooks_last_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'quickbooks_sync_status',
                'quickbooks_sync_error',
                'quickbooks_sync_requested_at',
                'quickbooks_last_synced_at',
            ]);
        });
    }
};
