<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_claims', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestampTz('claimed_at')->nullable();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        $hasExistingStaff = DB::table('users')->exists();

        DB::table('installation_claims')->insert([
            'id' => 1,
            'claimed_at' => $hasExistingStaff ? now() : null,
            'claimed_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_claims');
    }
};
