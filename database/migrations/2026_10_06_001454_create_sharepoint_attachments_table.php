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
        Schema::create('sharepoint_attachments', function (Blueprint $table) {
            $table->id();

            // In FieldOps, company_id is the customer/client company identity.
            $table->foreignId('company_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('filename', 255);
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('file_size');
            $table->string('sharepoint_drive_id', 255);
            $table->string('sharepoint_item_id', 255);
            $table->text('sharepoint_url')->nullable();
            $table->timestamps();

            $table->index('company_id', 'sharepoint_attachments_company_index');
            $table->index('site_id', 'sharepoint_attachments_site_index');
            $table->index('project_id', 'sharepoint_attachments_project_index');
            $table->index('job_id', 'sharepoint_attachments_job_index');
            $table->unique(
                ['sharepoint_drive_id', 'sharepoint_item_id'],
                'sharepoint_attachments_drive_item_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sharepoint_attachments');
    }
};
