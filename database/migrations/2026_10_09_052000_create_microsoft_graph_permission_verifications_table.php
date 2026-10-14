<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft_graph_onboarding_permission_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('target_id')->constrained('microsoft_graph_onboarding_targets')->restrictOnDelete();
            $table->string('environment', 20);
            $table->uuid('tenant_id');
            $table->uuid('client_id');
            $table->string('mailbox_id', 64);
            $table->string('business_site_id', 512);
            $table->string('business_drive_id', 512);
            $table->string('m365_admin_upn', 255);
            $table->string('verification_reference', 255);
            $table->boolean('mail_send_target_verified');
            $table->boolean('mail_send_non_target_denied');
            $table->boolean('calendar_write_target_verified');
            $table->boolean('calendar_write_non_target_denied');
            $table->boolean('contacts_boundary_verified');
            $table->boolean('sharepoint_boundary_verified');
            $table->boolean('sharepoint_retention_reviewed');
            $table->boolean('unscoped_app_roles_reviewed');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at');
            $table->timestampsTz();

            $table->index(['target_id', 'verified_at']);
            $table->index(['environment', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_graph_onboarding_permission_verifications');
    }
};
