<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sharepoint_attachments', function (Blueprint $table): void {
            $table->string('sharepoint_site_id', 512)->nullable()->after('sharepoint_drive_id');
            $table->string('sharepoint_target', 32)->default('work_schedules')->after('sharepoint_site_id');
        });
    }

    public function down(): void
    {
        Schema::table('sharepoint_attachments', function (Blueprint $table): void {
            $table->dropColumn(['sharepoint_site_id', 'sharepoint_target']);
        });
    }
};
