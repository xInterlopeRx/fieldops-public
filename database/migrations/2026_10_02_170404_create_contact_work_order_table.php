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
        Schema::create('contact_work_order', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['work_order_id', 'contact_id']);
            $table->index('contact_id', 'contact_work_order_contact_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_work_order');
    }
};
