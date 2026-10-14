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
        Schema::table('microsoft_graph_onboarding_permission_verifications', function (Blueprint $table): void {
            $table->string('non_target_mailbox_email', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('microsoft_graph_onboarding_permission_verifications', function (Blueprint $table): void {
            $table->dropColumn('non_target_mailbox_email');
        });
    }
};
