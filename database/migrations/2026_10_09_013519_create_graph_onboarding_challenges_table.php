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
        Schema::create('graph_onboarding_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('environment', 20);
            $table->foreignId('target_id')->constrained('microsoft_graph_onboarding_targets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('sending');
            $table->char('token_hash', 64)->unique();
            $table->char('recipient_email_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestampsTz();

            $table->index(['environment', 'status', 'expires_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('graph_onboarding_challenges');
    }
};
