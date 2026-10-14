<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('subtitle')->nullable()->after('title');
            $table->string('client_contact_name')->nullable();
            $table->string('client_contact_phone')->nullable();
            $table->string('client_contact_email')->nullable();
            $table->string('technical_contact_name')->nullable();
            $table->string('technical_contact_phone')->nullable();
            $table->string('technical_contact_email')->nullable();
            $table->string('project_manager_name')->nullable();
            $table->string('project_manager_phone')->nullable();
            $table->string('project_manager_email')->nullable();
            $table->string('contractor_check_in_name')->nullable();
            $table->string('contractor_check_in_phone')->nullable();
            $table->string('contractor_check_in_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'subtitle',
                'client_contact_name', 'client_contact_phone', 'client_contact_email',
                'technical_contact_name', 'technical_contact_phone', 'technical_contact_email',
                'project_manager_name', 'project_manager_phone', 'project_manager_email',
                'contractor_check_in_name', 'contractor_check_in_phone', 'contractor_check_in_email',
            ]);
        });
    }
};
