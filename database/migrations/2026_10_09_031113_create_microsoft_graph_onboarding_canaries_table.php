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
        Schema::create('microsoft_graph_onboarding_canaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('target_id')->constrained('microsoft_graph_onboarding_targets')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('environment', 20);
            $table->string('resource_type', 32);
            $table->string('status', 24);
            $table->uuid('transaction_id')->unique();
            $table->uuid('tenant_id');
            $table->uuid('client_id');
            $table->string('mailbox_id', 64);
            $table->string('calendar_id', 512)->nullable();
            $table->string('subject', 255);
            $table->string('remote_resource_id', 512)->nullable();
            $table->string('remote_etag', 512)->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampTz('created_remotely_at')->nullable();
            $table->timestampTz('cleaned_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_code', 80)->nullable();
            $table->timestampsTz();

            $table->index(['target_id', 'resource_type', 'status', 'created_at']);
            $table->index(['environment', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('microsoft_graph_onboarding_canaries');
    }
};
