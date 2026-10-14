<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_initial_customer_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20)->unique();
            $table->string('realm_id');
            $table->string('status', 32)->default('in_progress');
            $table->unsignedInteger('next_start_position')->default(1);
            $table->unsignedInteger('total_customers')->default(0);
            $table->unsignedInteger('processed_customers')->default(0);
            $table->unsignedInteger('imported_customers')->default(0);
            $table->unsignedInteger('imported_sites')->default(0);
            $table->string('failure_code', 80)->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('customer_sites_completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('quickbooks_initial_customer_import_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('quickbooks_initial_customer_imports')->cascadeOnDelete();
            $table->string('quickbooks_customer_id', 64);
            $table->string('failure_code', 80);
            $table->timestampsTz();
            $table->unique(['import_id', 'quickbooks_customer_id'], 'qbo_initial_import_failure_record_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_initial_customer_import_failures');
        Schema::dropIfExists('quickbooks_initial_customer_imports');
    }
};
