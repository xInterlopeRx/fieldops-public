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
            $table->foreignId('primary_contact_id')->nullable()->index();
            $table->foreign('primary_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });

        DB::table('contacts')
            ->whereNotNull('site_id')
            ->where('contact_type', 'primary')
            ->where('is_archived', false)
            ->select(['id', 'site_id'])
            ->orderBy('id')
            ->chunkById(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    DB::table('sites')
                        ->where('id', $contact->site_id)
                        ->whereNull('primary_contact_id')
                        ->update(['primary_contact_id' => $contact->id]);
                }
            });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
            DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');
            DB::statement("CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL AND is_archived = false AND contact_type <> 'other'");
            DB::statement("CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL AND is_archived = false AND contact_type <> 'other'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $unrestorableSiteContact = DB::table('sites')
            ->join('contacts', 'contacts.id', '=', 'sites.primary_contact_id')
            ->whereNotNull('sites.primary_contact_id')
            ->where(function ($query): void {
                $query->whereNull('contacts.site_id')
                    ->orWhereColumn('contacts.site_id', '!=', 'sites.id')
                    ->orWhere('contacts.contact_type', '!=', 'primary');
            })
            ->exists();

        if ($unrestorableSiteContact) {
            throw new RuntimeException('Shared Site primary-contact assignments cannot be represented by the previous Contact.site_id model.');
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $duplicateAdditionalContacts = DB::table('contacts')
                ->where('is_archived', false)
                ->where('contact_type', 'other')
                ->select('customer_id', 'site_id')
                ->groupBy('customer_id', 'site_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicateAdditionalContacts) {
                throw new RuntimeException('Multiple Additional contacts prevent restoring the previous contact-role uniqueness indexes.');
            }

            DB::statement('DROP INDEX IF EXISTS contacts_customer_contact_type_unique');
            DB::statement('DROP INDEX IF EXISTS contacts_site_contact_type_unique');
            DB::statement('CREATE UNIQUE INDEX contacts_customer_contact_type_unique ON contacts (customer_id, contact_type) WHERE site_id IS NULL AND is_archived = false');
            DB::statement('CREATE UNIQUE INDEX contacts_site_contact_type_unique ON contacts (site_id, contact_type) WHERE site_id IS NOT NULL AND is_archived = false');
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropForeign(['primary_contact_id']);
            $table->dropIndex(['primary_contact_id']);
            $table->dropColumn('primary_contact_id');
        });
    }
};
