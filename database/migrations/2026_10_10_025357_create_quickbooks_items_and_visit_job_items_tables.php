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
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->string('quickbooks_realm_id', 255);
            $table->string('quickbooks_id', 64);
            $table->string('name', 100);
            $table->string('type', 30);
            $table->boolean('active')->default(true);
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['quickbooks_realm_id', 'quickbooks_id']);
            $table->index(['quickbooks_realm_id', 'active', 'type', 'name']);
        });

        Schema::create('job_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->string('quickbooks_item_id', 64);
            $table->string('item_name_snapshot', 100);
            $table->decimal('quantity', 12, 3);
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['visit_id', 'item_id']);
            $table->index(['item_id', 'created_at']);
        });

        Schema::create('quickbooks_item_catalog_syncs', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20)->unique();
            $table->string('realm_id', 255);
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('next_start_position')->default(1);
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('imported_items')->default(0);
            $table->string('failure_code', 80)->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['environment', 'status']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('job_items')->exists()
            || DB::table('items')->exists()
            || DB::table('quickbooks_item_catalog_syncs')->exists()) {
            throw new RuntimeException('Cannot roll back QuickBooks Item support while Item or Visit usage records exist.');
        }

        Schema::dropIfExists('quickbooks_item_catalog_syncs');
        Schema::dropIfExists('job_items');
        Schema::dropIfExists('items');
    }
};
