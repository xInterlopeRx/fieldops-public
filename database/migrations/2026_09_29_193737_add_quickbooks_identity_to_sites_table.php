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
        Schema::table('sites', function (Blueprint $table) {
            $table->string('name', 500)->change();
            $table->string('quickbooks_id')->nullable()->unique();
            $table->string('quickbooks_sync_token')->nullable();
            $table->boolean('quickbooks_active')->nullable();
            $table->string('quickbooks_parent_id')->nullable();
            $table->unsignedSmallInteger('quickbooks_level')->nullable();
            $table->boolean('quickbooks_is_job')->nullable();
            $table->boolean('quickbooks_bill_with_parent')->nullable();
            $table->boolean('quickbooks_is_project')->nullable();
            $table->string('quickbooks_fully_qualified_name', 500)->nullable();
            $table->timestamp('quickbooks_created_at')->nullable();
            $table->timestamp('quickbooks_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sites')->whereRaw('length(name) > ?', [255])->exists()) {
            throw new RuntimeException('Site names longer than 255 characters prevent shrinking the name column.');
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['quickbooks_id']);
            $table->dropColumn([
                'quickbooks_id',
                'quickbooks_sync_token',
                'quickbooks_active',
                'quickbooks_parent_id',
                'quickbooks_level',
                'quickbooks_is_job',
                'quickbooks_bill_with_parent',
                'quickbooks_is_project',
                'quickbooks_fully_qualified_name',
                'quickbooks_created_at',
                'quickbooks_updated_at',
            ]);
            $table->string('name', 255)->change();
        });
    }
};
