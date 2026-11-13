<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_transport_configurations', function (Blueprint $table): void {
            $table->string('tested_recipient')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_transport_configurations', function (Blueprint $table): void {
            $table->dropColumn('tested_recipient');
        });
    }
};
