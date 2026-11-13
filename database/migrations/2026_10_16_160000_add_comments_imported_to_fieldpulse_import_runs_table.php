<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('comments_imported')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->dropColumn('comments_imported');
        });
    }
};
