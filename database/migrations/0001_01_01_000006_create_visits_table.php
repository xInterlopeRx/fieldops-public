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
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
            $table->dateTime('scheduled_at'); // The primary date for the calendar view.
            $table->string('notes')->nullable();
            $table->jsonb('metadata')->nullable(); // For flexible attributes like QuickBooks IDs, etc.
            $table->timestamps();

            // Indexing scheduled_at is crucial as it's our main query filter for the monthly grid.
            $table->index(['scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
