<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_graph_onboarding_targets', function (Blueprint $table): void {
            $table->timestampTz('business_attachments_writes_activated_at')->nullable();
            $table->foreignId('business_attachments_writes_activated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_graph_onboarding_targets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_attachments_writes_activated_by_user_id');
            $table->dropColumn('business_attachments_writes_activated_at');
        });
    }
};
