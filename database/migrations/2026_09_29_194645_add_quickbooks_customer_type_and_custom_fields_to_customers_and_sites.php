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
            $table->string('quickbooks_customer_type_id')->nullable();
            $table->string('quickbooks_customer_type_name')->nullable();
            $table->jsonb('quickbooks_custom_fields')->nullable();
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->string('quickbooks_customer_type_id')->nullable();
            $table->string('quickbooks_customer_type_name')->nullable();
            $table->jsonb('quickbooks_custom_fields')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'quickbooks_customer_type_id',
                'quickbooks_customer_type_name',
                'quickbooks_custom_fields',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'quickbooks_customer_type_id',
                'quickbooks_customer_type_name',
                'quickbooks_custom_fields',
            ]);
        });
    }
};
