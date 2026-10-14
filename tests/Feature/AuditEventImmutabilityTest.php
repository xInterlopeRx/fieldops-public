<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditEventImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_updates_to_activity_events_are_rejected(): void
    {
        $event = $this->event();

        $this->expectException(\LogicException::class);

        $event->update(['summary' => 'Edited']);
    }

    public function test_query_builder_updates_to_activity_events_are_rejected_by_the_database(): void
    {
        $event = $this->event();

        $this->expectException(QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->update(['summary' => 'Edited']);
    }

    public function test_query_builder_deletes_of_activity_events_are_rejected_by_the_database(): void
    {
        $event = $this->event();

        $this->expectException(QueryException::class);

        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    public function test_model_deletes_of_activity_events_are_rejected(): void
    {
        $event = $this->event();

        $this->expectException(\LogicException::class);

        $event->delete();
    }

    public function test_actor_deletion_cannot_break_audit_attribution(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        AuditEvent::query()->create([
            'actor_user_id' => $actor->id,
            'subject_type' => 'App\\Models\\Customer',
            'subject_id' => 1,
            'event' => 'edited',
            'summary' => 'Actor edited a customer.',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        $actor->delete();
    }

    private function event(): AuditEvent
    {
        return AuditEvent::query()->create([
            'subject_type' => 'App\\Models\\Customer',
            'subject_id' => 1,
            'event' => 'edited',
            'summary' => 'Technician edited customer C00001.',
            'metadata' => ['changed_fields' => ['name']],
            'created_at' => now(),
        ]);
    }
}
