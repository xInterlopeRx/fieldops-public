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
            $table->string('quickbooks_write_request_id', 50)->nullable()->unique();
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->string('quickbooks_write_request_id', 50)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique(['quickbooks_write_request_id']);
            $table->dropColumn('quickbooks_write_request_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['quickbooks_write_request_id']);
            $table->dropColumn('quickbooks_write_request_id');
        });
    }
};
