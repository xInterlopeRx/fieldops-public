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
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('graph_sync_status')->default('not_requested')->change();
        });

        DB::table('visits')
            ->whereNull('microsoft_graph_event_id')
            ->update(['graph_sync_status' => 'not_requested']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('graph_sync_status')->default('pending')->change();
        });
    }
};
