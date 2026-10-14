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
        Schema::table('sites', function (Blueprint $table): void {
            $table->index('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });

        foreach ([
            ['contacts', 'customer_id', 'customers'],
            ['contacts', 'site_id', 'sites'],
            ['customer_addresses', 'customer_id', 'customers'],
            ['customer_addresses', 'source_site_id', 'sites'],
            ['projects', 'customer_id', 'customers'],
            ['work_orders', 'site_id', 'sites'],
            ['work_orders', 'project_id', 'projects'],
            ['visits', 'work_order_id', 'work_orders'],
            ['visits', 'initial_visit_id', 'visits'],
            ['project_attachments', 'project_id', 'projects'],
            ['work_order_attachments', 'work_order_id', 'work_orders'],
            ['project_site', 'project_id', 'projects'],
            ['project_site', 'site_id', 'sites'],
            ['work_order_user', 'work_order_id', 'work_orders'],
            ['visit_user', 'visit_id', 'visits'],
        ] as [$tableName, $column, $referencedTable]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable): void {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on($referencedTable)->restrictOnDelete();
            });
        }

        $this->createArchiveAwareUniquenessIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            ['visit_user', 'visit_id', 'visits', 'cascade'],
            ['work_order_user', 'work_order_id', 'work_orders', 'cascade'],
            ['project_site', 'site_id', 'sites', 'cascade'],
            ['project_site', 'project_id', 'projects', 'cascade'],
            ['work_order_attachments', 'work_order_id', 'work_orders', 'cascade'],
            ['project_attachments', 'project_id', 'projects', 'cascade'],
            ['visits', 'work_order_id', 'work_orders', 'cascade'],
            ['visits', 'initial_visit_id', 'visits', 'null'],
            ['work_orders', 'project_id', 'projects', 'null'],
            ['work_orders', 'site_id', 'sites', 'cascade'],
            ['projects', 'customer_id', 'customers', 'cascade'],
            ['customer_addresses', 'source_site_id', 'sites', 'null'],
            ['customer_addresses', 'customer_id', 'customers', 'cascade'],
            ['contacts', 'site_id', 'sites', 'null'],
            ['contacts', 'customer_id', 'customers', 'cascade'],
        ] as [$tableName, $column, $referencedTable, $deleteAction]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $referencedTable, $deleteAction): void {
                $table->dropForeign([$column]);
                $foreign = $table->foreign($column)->references('id')->on($referencedTable);

                if ($deleteAction === 'cascade') {
                    $foreign->cascadeOnDelete();
                } elseif ($deleteAction === 'null') {
                    $foreign->nullOnDelete();
                }
            });
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id']);
        });

        $this->createArchiveAwareUniquenessIndexes();
    }

    private function createArchiveAwareUniquenessIndexes(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
        DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');
        DB::statement('CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL AND is_archived = false');
        DB::statement('CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL AND is_archived = false');
        DB::statement('DROP INDEX IF EXISTS customer_addresses_one_default_per_type_unique');
        DB::statement('CREATE UNIQUE INDEX customer_addresses_one_default_per_type_unique ON customer_addresses (customer_id, address_type) WHERE is_default = true AND is_archived = false');
    }
};
