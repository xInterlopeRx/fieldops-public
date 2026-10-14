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
        Schema::table('contact_methods', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX contact_methods_one_primary_per_type_unique ON contact_methods (contact_id, method_type) WHERE is_primary = true');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS contact_methods_one_primary_per_type_unique');
        }

        Schema::table('contact_methods', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
