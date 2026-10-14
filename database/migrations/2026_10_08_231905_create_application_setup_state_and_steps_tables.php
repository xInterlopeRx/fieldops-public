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
        Schema::create('application_setup_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('onboarding_required')->default(true);
            $table->timestampTz('readiness_completed_at')->nullable();
            $table->foreignId('readiness_completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('readiness_receipt_type', 16)->nullable();
            $table->timestampsTz();
        });

        Schema::create('application_setup_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('step_key', 40)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->unsignedInteger('progress_current')->nullable();
            $table->unsignedInteger('progress_total')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'step_key']);
        });

        $legacyInstallation = DB::table('users')->exists();
        $now = now();

        DB::table('application_setup_states')->insert([
            'id' => 1,
            'onboarding_required' => ! $legacyInstallation,
            'readiness_completed_at' => $legacyInstallation ? $now : null,
            'readiness_completed_by_user_id' => null,
            'readiness_receipt_type' => $legacyInstallation ? 'legacy' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stepKeys = [
            'first_admin',
            'bws_runtime_reader',
            'quickbooks_oauth',
            'initial_qbo_import',
            'graph_validation',
            'failure_reconciliation',
        ];

        foreach ($stepKeys as $stepKey) {
            DB::table('application_setup_steps')->insert([
                'step_key' => $stepKey,
                'status' => $legacyInstallation ? 'not_required' : 'pending',
                'attempt_count' => 0,
                'completed_at' => $legacyInstallation ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_setup_steps');
        Schema::dropIfExists('application_setup_states');
    }
};
