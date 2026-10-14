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
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->text('scope')->nullable();
            $table->text('client_completion_summary')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->text('scope')->nullable();
            $table->string('microsoft_graph_event_id')->nullable()->unique();
            $table->uuid('microsoft_graph_transaction_id')->nullable()->unique();
            $table->string('graph_sync_status')->default('pending');
            $table->string('graph_sync_error')->nullable();
            $table->timestampTz('graph_synced_at')->nullable();
            $table->timestampTz('rsvp_polled_at')->nullable();
            $table->jsonb('attendee_responses')->nullable();
            $table->timestampTz('rsvp_attention_notified_at')->nullable();
            $table->index(['graph_sync_status', 'scheduled_at']);
        });

        Schema::table('work_order_attachments', function (Blueprint $table): void {
            $table->foreignId('visit_id')->nullable()->constrained('visits')->restrictOnDelete();
            $table->index(['visit_id', 'created_at']);
        });

        Schema::create('graph_notification_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->restrictOnDelete();
            $table->string('type', 50);
            $table->string('recipient_email');
            $table->string('recipient_role', 50)->nullable();
            $table->string('event_key')->unique();
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at']);
            $table->index(['work_order_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('graph_notification_outbox');

        Schema::table('work_order_attachments', function (Blueprint $table): void {
            $table->dropForeign(['visit_id']);
            $table->dropIndex(['visit_id', 'created_at']);
            $table->dropColumn('visit_id');
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->dropIndex(['graph_sync_status', 'scheduled_at']);
            $table->dropUnique(['microsoft_graph_event_id']);
            $table->dropUnique(['microsoft_graph_transaction_id']);
            $table->dropColumn([
                'scope',
                'microsoft_graph_event_id',
                'microsoft_graph_transaction_id',
                'graph_sync_status',
                'graph_sync_error',
                'graph_synced_at',
                'rsvp_polled_at',
                'attendee_responses',
                'rsvp_attention_notified_at',
            ]);
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['scope', 'client_completion_summary', 'completed_at']);
        });
    }
};
