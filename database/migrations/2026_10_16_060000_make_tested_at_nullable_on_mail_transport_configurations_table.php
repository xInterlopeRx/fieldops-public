<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A saved bootstrap configuration can outlive its verification: a later
     * failed re-test clears the verified marker but keeps the settings and
     * encrypted credentials for reuse. Allowing a null `tested_at` distinguishes
     * "saved but not currently verified" from "verified".
     */
    public function up(): void
    {
        Schema::table('mail_transport_configurations', function (Blueprint $table): void {
            $table->timestampTz('tested_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mail_transport_configurations', function (Blueprint $table): void {
            $table->timestampTz('tested_at')->nullable(false)->change();
        });
    }
};
