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
            $table->boolean('target_is_dedicated_shared_mailbox')->default(false);
            $table->boolean('non_target_is_user_mailbox')->default(false);
        });

        Schema::table('microsoft_graph_onboarding_permission_verifications', function (Blueprint $table): void {
            $table->boolean('target_is_dedicated_shared_mailbox')->default(false);
            $table->boolean('non_target_is_user_mailbox')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('microsoft_graph_onboarding_permission_verifications', function (Blueprint $table): void {
            $table->dropColumn(['target_is_dedicated_shared_mailbox', 'non_target_is_user_mailbox']);
        });

        Schema::table('microsoft_graph_onboarding_targets', function (Blueprint $table): void {
            $table->dropColumn(['target_is_dedicated_shared_mailbox', 'non_target_is_user_mailbox']);
        });
    }
};
