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
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('microsoft_graph_event_tenant_id', 36)->nullable();
            $table->string('microsoft_graph_event_mailbox_id', 36)->nullable();
            $table->string('microsoft_graph_event_calendar_id', 512)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn([
                'microsoft_graph_event_tenant_id',
                'microsoft_graph_event_mailbox_id',
                'microsoft_graph_event_calendar_id',
            ]);
        });
    }
};
