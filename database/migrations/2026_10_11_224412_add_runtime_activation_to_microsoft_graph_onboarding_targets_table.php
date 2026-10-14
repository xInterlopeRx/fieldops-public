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
        Schema::table('microsoft_graph_onboarding_targets', function (Blueprint $table): void {
            $table->timestampTz('runtime_activated_at')->nullable();
            $table->foreignId('runtime_activated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('microsoft_graph_onboarding_targets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('runtime_activated_by_user_id');
            $table->dropColumn('runtime_activated_at');
        });
    }
};
