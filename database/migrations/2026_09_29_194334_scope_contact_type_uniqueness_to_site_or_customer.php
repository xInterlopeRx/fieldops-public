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
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['customer_id', 'contact_type']);
        });

        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('contacts')
            ->select('customer_id', 'contact_type')
            ->groupBy('customer_id', 'contact_type')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Duplicate contact types prevent restoring customer-level uniqueness.');
        }

        DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
        DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');

        Schema::table('contacts', function (Blueprint $table): void {
            $table->unique(['customer_id', 'contact_type']);
        });
    }
};
