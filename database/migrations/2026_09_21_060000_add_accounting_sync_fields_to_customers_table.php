<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('quickbooks_id')->nullable()->unique()->after('client_id');
            $table->string('invoice_ninja_id')->nullable()->unique()->after('quickbooks_id');
            $table->string('accounting_sync_status')->default('pending')->after('invoice_ninja_id');
            $table->text('accounting_sync_error')->nullable()->after('accounting_sync_status');
            $table->timestamp('accounting_synced_at')->nullable()->after('accounting_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['quickbooks_id']);
            $table->dropUnique(['invoice_ninja_id']);
            $table->dropColumn([
                'quickbooks_id',
                'invoice_ninja_id',
                'accounting_sync_status',
                'accounting_sync_error',
                'accounting_synced_at',
            ]);
        });
    }
};