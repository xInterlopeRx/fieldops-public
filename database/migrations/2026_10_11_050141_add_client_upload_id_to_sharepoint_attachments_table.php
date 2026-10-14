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
        Schema::table('sharepoint_attachments', function (Blueprint $table): void {
            $table->uuid('client_upload_id')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sharepoint_attachments')->whereNotNull('client_upload_id')->exists()) {
            throw new RuntimeException('Cannot remove upload idempotency keys while offline-upload metadata still uses them.');
        }

        Schema::table('sharepoint_attachments', function (Blueprint $table): void {
            $table->dropUnique(['client_upload_id']);
            $table->dropColumn('client_upload_id');
        });
    }
};
