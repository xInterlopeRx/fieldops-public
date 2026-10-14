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
        Schema::table('items', function (Blueprint $table): void {
            $table->text('description')->nullable();
        });

        Schema::table('job_items', function (Blueprint $table): void {
            $table->text('item_description_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('items')->whereNotNull('description')->exists()
            || DB::table('job_items')->whereNotNull('item_description_snapshot')->exists()) {
            throw new RuntimeException('Cannot roll back Item descriptions while catalog or Visit usage descriptions exist.');
        }

        Schema::table('job_items', function (Blueprint $table): void {
            $table->dropColumn('item_description_snapshot');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
