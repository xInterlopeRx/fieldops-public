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
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('client_contact_id')->nullable()->after('client_contact_email')->constrained('contacts')->nullOnDelete();
            $table->foreignId('technical_contact_id')->nullable()->after('technical_contact_email')->constrained('contacts')->nullOnDelete();
            $table->foreignId('project_manager_contact_id')->nullable()->after('project_manager_email')->constrained('contacts')->nullOnDelete();
            $table->foreignId('contractor_check_in_contact_id')->nullable()->after('contractor_check_in_email')->constrained('contacts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_contact_id');
            $table->dropConstrainedForeignId('technical_contact_id');
            $table->dropConstrainedForeignId('project_manager_contact_id');
            $table->dropConstrainedForeignId('contractor_check_in_contact_id');
        });
    }
};
