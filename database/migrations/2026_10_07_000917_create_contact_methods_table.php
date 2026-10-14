<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contact_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->enum('method_type', ['email', 'phone', 'teams']);
            $table->enum('label', ['office', 'mobile', 'dispatch', 'after_hours', 'operations', 'personal']);
            $table->string('value', 1000);
            $table->timestamps();

            $table->index(['contact_id', 'method_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_methods');
    }
};
