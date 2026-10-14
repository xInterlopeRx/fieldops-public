<?php

namespace Tests\Feature;

use App\Services\QueueWorkerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QueueWorkerStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_worker_heartbeats_are_reported_for_each_monitored_queue_and_become_stale(): void
    {
        config(['cache.default' => 'database']);
        Cache::store('database')->flush();

        Event::dispatch(new Looping('database', 'default,quickbooks-initial-import'));

        $status = app(QueueWorkerStatus::class);
        $this->assertSame('recently_seen', $status->status('database', 'default')['state']);
        $this->assertSame('recently_seen', $status->status('database', 'quickbooks-initial-import')['state']);

        $this->travel(QueueWorkerStatus::RECENT_AFTER_SECONDS + 1)->seconds();

        $this->assertSame('stale', $status->status('database', 'default')['state']);
    }

    public function test_worker_status_is_unknown_when_the_cache_cannot_be_shared_with_a_worker(): void
    {
        config(['cache.default' => 'array']);

        $this->assertSame(
            ['state' => 'unknown', 'lastSeenAt' => null, 'ageSeconds' => null],
            app(QueueWorkerStatus::class)->status('database', 'default'),
        );
    }
}
