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
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
            DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');
            DB::statement("CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL AND is_archived = false AND contact_type <> 'other'");
            DB::statement("CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL AND is_archived = false AND contact_type <> 'other'");
        }

        Schema::create('customer_email_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('email', 255);
            $table->string('label', 100)->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->uuid('archive_batch_id')->nullable();
            $table->foreign('archive_batch_id')->references('id')->on('archive_batches')->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'is_archived'], 'customer_email_addresses_customer_active_index');
            $table->index('archive_batch_id', 'customer_email_addresses_archive_batch_index');
        });

        Schema::create('customer_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40)->default('documents');
            $table->string('original_name');
            $table->string('disk', 80);
            $table->string('path');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->uuid('archive_batch_id')->nullable();
            $table->foreign('archive_batch_id')->references('id')->on('archive_batches')->restrictOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'is_archived'], 'customer_attachments_customer_active_index');
            $table->index('archive_batch_id', 'customer_attachments_archive_batch_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $duplicateContacts = DB::table('contacts')
                ->where('is_archived', false)
                ->select('customer_id', 'site_id', 'contact_type')
                ->groupBy('customer_id', 'site_id', 'contact_type')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicateContacts) {
                throw new RuntimeException('Duplicate contact types exist; refusing to restore the former uniqueness constraint.');
            }

            DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
            DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');
            DB::statement('CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL AND is_archived = false');
            DB::statement('CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL AND is_archived = false');
        }

        Schema::dropIfExists('customer_attachments');
        Schema::dropIfExists('customer_email_addresses');
    }
};
