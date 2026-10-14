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
        Schema::create('archive_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('root_type', 40);
            $table->unsignedBigInteger('root_id');
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('archived_at');
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('restored_at')->nullable();
            $table->timestampsTz();

            $table->index(['root_type', 'root_id'], 'archive_batches_root_index');
            $table->index(['restored_at', 'archived_at'], 'archive_batches_state_index');
        });

        foreach ([
            'customers',
            'sites',
            'projects',
            'work_orders',
            'visits',
            'contacts',
            'customer_addresses',
            'project_attachments',
            'work_order_attachments',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->boolean('is_archived')->default(false);
                $table->timestampTz('archived_at')->nullable();
                $table->uuid('archive_batch_id')->nullable();
                $table->foreign('archive_batch_id')
                    ->references('id')
                    ->on('archive_batches')
                    ->restrictOnDelete();
                $table->index(['is_archived', 'archived_at'], $tableName.'_archive_state_index');
                $table->index('archive_batch_id', $tableName.'_archive_batch_index');
            });
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
            DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');
            DB::statement('CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL AND is_archived = false');
            DB::statement('CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL AND is_archived = false');
            DB::statement('DROP INDEX IF EXISTS customer_addresses_one_default_per_type_unique');
            DB::statement('CREATE UNIQUE INDEX customer_addresses_one_default_per_type_unique ON customer_addresses (customer_id, address_type) WHERE is_default = true AND is_archived = false');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('archive_batches')->exists()) {
            throw new RuntimeException('Archive history exists; refusing to remove archive state and make archived records active.');
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
            DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');
            DB::statement('CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL');
            DB::statement('DROP INDEX IF EXISTS customer_addresses_one_default_per_type_unique');
            DB::statement('CREATE UNIQUE INDEX customer_addresses_one_default_per_type_unique ON customer_addresses (customer_id, address_type) WHERE is_default = true');
        }

        foreach ([
            'work_order_attachments',
            'project_attachments',
            'customer_addresses',
            'contacts',
            'visits',
            'work_orders',
            'projects',
            'sites',
            'customers',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['archive_batch_id']);
                $table->dropIndex($tableName.'_archive_state_index');
                $table->dropIndex($tableName.'_archive_batch_index');
                $table->dropColumn(['is_archived', 'archived_at', 'archive_batch_id']);
            });
        }

        Schema::dropIfExists('archive_batches');
    }
};
