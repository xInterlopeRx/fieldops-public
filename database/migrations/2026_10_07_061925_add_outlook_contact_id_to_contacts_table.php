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
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('outlook_contact_id')->nullable()->unique();
            $table->string('outlook_contact_mailbox_id')->nullable();
            $table->string('outlook_sync_status', 24)->default('not_synced')->index();
            $table->timestamp('outlook_sync_requested_at')->nullable();
            $table->timestamp('outlook_last_synced_at')->nullable();
            $table->string('outlook_sync_error', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['outlook_contact_id']);
            $table->dropIndex(['outlook_sync_status']);
            $table->dropColumn([
                'outlook_contact_id',
                'outlook_contact_mailbox_id',
                'outlook_sync_status',
                'outlook_sync_requested_at',
                'outlook_last_synced_at',
                'outlook_sync_error',
            ]);
        });
    }
};
