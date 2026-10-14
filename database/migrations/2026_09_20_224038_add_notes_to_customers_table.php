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
        Schema::table('customers', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('address');
        });

        DB::table('customers')
            ->select(['id', 'metadata'])
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(100, function ($customers): void {
                foreach ($customers as $customer) {
                    $metadata = json_decode((string) $customer->metadata, true);
                    $notes = $metadata['notes'] ?? null;

                    if ($notes !== null && $notes !== '') {
                        DB::table('customers')
                            ->where('id', $customer->id)
                            ->update(['notes' => $notes]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
