<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft_graph_sharepoint_onboarding_canaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('target_id')->constrained('microsoft_graph_onboarding_targets')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('environment', 20);
            $table->uuid('tenant_id');
            $table->uuid('client_id');
            $table->string('site_id', 512);
            $table->string('drive_id', 512);
            $table->string('status', 24);
            $table->uuid('operation_id')->unique();
            $table->string('folder_name', 255);
            $table->string('remote_item_id', 512)->nullable();
            $table->string('remote_etag', 512)->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampTz('created_remotely_at')->nullable();
            $table->timestampTz('cleaned_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_code', 80)->nullable();
            $table->timestampsTz();

            $table->index(['target_id', 'status', 'created_at']);
            $table->index(['environment', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_graph_sharepoint_onboarding_canaries');
    }
};
