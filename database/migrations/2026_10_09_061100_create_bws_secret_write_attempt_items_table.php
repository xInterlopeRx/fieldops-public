<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bws_secret_write_attempt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('attempt_id')->constrained('bws_secret_write_attempts')->restrictOnDelete();
            $table->string('secret_name', 64);
            $table->char('expected_fingerprint', 64);
            $table->string('status', 32);
            $table->string('write_status', 16)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['attempt_id', 'secret_name']);
            $table->index(['attempt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bws_secret_write_attempt_items');
    }
};
