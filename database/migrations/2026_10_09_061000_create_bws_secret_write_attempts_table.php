<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bws_secret_write_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('connection_id')->constrained('bitwarden_secret_manager_connections')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('environment', 20);
            $table->uuid('organization_id');
            $table->uuid('project_id');
            $table->string('status', 32);
            $table->string('fingerprint_key_id', 24);
            $table->string('failure_code', 80)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reconciliation_reference', 80)->nullable();
            $table->timestampsTz();

            $table->index(['environment', 'status', 'started_at']);
            $table->index(['connection_id', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bws_secret_write_attempts');
    }
};
