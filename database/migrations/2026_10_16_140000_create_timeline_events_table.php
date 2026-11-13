<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestampTz('occurred_at')->nullable();
            $table->string('kind', 32);
            $table->text('body');
            $table->string('source', 20);
            $table->string('source_record_id', 255);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique(['source', 'source_record_id'], 'timeline_events_source_record_unique');
            $table->index(['customer_id', 'occurred_at'], 'timeline_events_customer_timeline_index');
            $table->index(['site_id', 'occurred_at'], 'timeline_events_site_timeline_index');
        });

        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('notes_imported')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('fieldpulse_import_runs', function (Blueprint $table): void {
            $table->dropColumn('notes_imported');
        });

        Schema::dropIfExists('timeline_events');
    }
};
