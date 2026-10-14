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
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('address_type', 20);
            $table->string('source', 20)->default('manual');
            $table->string('label')->nullable();
            $table->string('quickbooks_address_id')->nullable();
            $table->string('line1', 500)->nullable();
            $table->string('line2', 500)->nullable();
            $table->string('line3', 500)->nullable();
            $table->string('line4', 500)->nullable();
            $table->string('line5', 500)->nullable();
            $table->string('city')->nullable();
            $table->string('country_subdivision_code')->nullable();
            $table->string('postal_code', 64)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('latitude', 64)->nullable();
            $table->string('longitude', 64)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'address_type']);
        });

        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $defaultPredicate = $driver === 'sqlite' ? 'is_default = 1' : 'is_default = true';
            DB::statement("CREATE UNIQUE INDEX customer_addresses_one_default_per_type_unique ON customer_addresses (customer_id, address_type) WHERE {$defaultPredicate}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
