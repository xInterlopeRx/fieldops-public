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
        // Add assigned_user to work_orders for general task ownership
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->index('assigned_user_id');
        });

        // Add assigned_user to visits for specific scheduling ownership
        // (One work order might have multiple visits by different people)
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->index('assigned_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropColumn('assigned_user_id');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropColumn('assigned_user_id');
        });
    }
};
