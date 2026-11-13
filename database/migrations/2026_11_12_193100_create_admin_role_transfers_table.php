<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_role_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['to_user_id', 'completed_at', 'expires_at'], 'admin_role_transfers_target_state_index');
            $table->index(['from_user_id', 'completed_at', 'expires_at'], 'admin_role_transfers_source_state_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('admin_role_transfers') && DB::table('admin_role_transfers')->exists()) {
            throw new RuntimeException('Admin role transfer history exists; use a forward migration to roll back this feature.');
        }

        Schema::dropIfExists('admin_role_transfers');
    }
};
