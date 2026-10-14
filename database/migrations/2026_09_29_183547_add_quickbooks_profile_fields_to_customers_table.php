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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name', 500)->change();
            $table->string('company_name', 100)->nullable();
            $table->string('middle_name')->nullable();
            $table->string('title')->nullable();
            $table->string('suffix')->nullable();
            $table->string('quickbooks_fully_qualified_name', 500)->nullable();
            $table->string('quickbooks_print_on_check_name', 500)->nullable();
            $table->string('quickbooks_sync_token')->nullable();
            $table->boolean('quickbooks_active')->nullable();
            $table->string('quickbooks_parent_id')->nullable();
            $table->unsignedSmallInteger('quickbooks_level')->nullable();
            $table->boolean('quickbooks_is_job')->nullable();
            $table->boolean('quickbooks_bill_with_parent')->nullable();
            $table->boolean('quickbooks_is_project')->nullable();
            $table->string('preferred_delivery_method', 50)->nullable();
            $table->string('alternate_phone', 64)->nullable();
            $table->string('mobile_phone', 64)->nullable();
            $table->string('fax', 64)->nullable();
            $table->string('website', 1000)->nullable();
            $table->timestamp('quickbooks_created_at')->nullable();
            $table->timestamp('quickbooks_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customers')->whereRaw('length(name) > ?', [255])->exists()) {
            throw new RuntimeException('Customer names longer than 255 characters prevent shrinking the name column.');
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'middle_name',
                'title',
                'suffix',
                'quickbooks_fully_qualified_name',
                'quickbooks_print_on_check_name',
                'quickbooks_sync_token',
                'quickbooks_active',
                'quickbooks_parent_id',
                'quickbooks_level',
                'quickbooks_is_job',
                'quickbooks_bill_with_parent',
                'quickbooks_is_project',
                'preferred_delivery_method',
                'alternate_phone',
                'mobile_phone',
                'fax',
                'website',
                'quickbooks_created_at',
                'quickbooks_updated_at',
            ]);
            $table->string('name', 255)->change();
        });
    }
};
