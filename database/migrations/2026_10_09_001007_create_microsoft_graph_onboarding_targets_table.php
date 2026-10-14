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
        Schema::create('microsoft_graph_onboarding_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 20)->unique();
            $table->uuid('tenant_id');
            $table->uuid('client_id');
            $table->string('notification_mailbox_id', 64);
            $table->string('notification_mailbox_address', 255);
            $table->string('notification_calendar_id', 512)->nullable();
            $table->uuid('work_schedules_group_id');
            $table->string('work_schedules_site_id', 512);
            $table->string('work_schedules_drive_id', 512);
            $table->string('business_attachments_site_id', 512)->nullable();
            $table->string('business_attachments_drive_id', 512)->nullable();
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('configured_at')->nullable();
            $table->string('validation_status', 24)->default('pending');
            $table->timestampTz('last_validation_attempted_at')->nullable();
            $table->timestampTz('read_access_verified_at')->nullable();
            $table->unsignedSmallInteger('read_access_check_count')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('microsoft_graph_onboarding_targets');
    }
};
