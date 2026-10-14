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
            $table->uuid('non_target_mailbox_id')->nullable();
            $table->string('non_target_site_id', 512)->nullable();
            $table->string('non_target_drive_id', 512)->nullable();
            $table->string('non_target_mailbox_calendar_status', 20)->default('not_checked');
            $table->string('non_target_site_status', 20)->default('not_checked');
            $table->string('non_target_drive_status', 20)->default('not_checked');
            $table->timestampTz('boundary_validation_attempted_at')->nullable();
            $table->timestampTz('boundary_verified_at')->nullable();
            $table->string('boundary_error_code', 80)->nullable();
            $table->string('target_contacts_status', 20)->default('not_checked');
            $table->string('non_target_contacts_status', 20)->default('not_checked');
            $table->timestampTz('contacts_boundary_validation_attempted_at')->nullable();
            $table->timestampTz('contacts_boundary_verified_at')->nullable();
            $table->string('contacts_boundary_error_code', 80)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('microsoft_graph_onboarding_targets', function (Blueprint $table): void {
            $table->dropColumn([
                'non_target_mailbox_id',
                'non_target_site_id',
                'non_target_drive_id',
                'non_target_mailbox_calendar_status',
                'non_target_site_status',
                'non_target_drive_status',
                'boundary_validation_attempted_at',
                'boundary_verified_at',
                'boundary_error_code',
                'target_contacts_status',
                'non_target_contacts_status',
                'contacts_boundary_validation_attempted_at',
                'contacts_boundary_verified_at',
                'contacts_boundary_error_code',
            ]);
        });
    }
};
