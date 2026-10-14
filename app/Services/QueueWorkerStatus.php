<?php

namespace App\Services;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Throwable;

class QueueWorkerStatus
{
    public const RECENT_AFTER_SECONDS = 180;

    private const WRITE_INTERVAL_SECONDS = 15;

    private const HEARTBEAT_TTL_SECONDS = 86400;

    /** @var array<string, int> */
    private array $lastWrittenAt = [];

    public function recordLooping(Looping $event): void
    {
        $now = now()->timestamp;

        foreach (array_unique(array_filter(array_map('trim', explode(',', (string) $event->queue)))) as $queue) {
            $key = $this->key((string) $event->connectionName, $queue);

            if (($this->lastWrittenAt[$key] ?? 0) + self::WRITE_INTERVAL_SECONDS > $now) {
                continue;
            }

            try {
                Cache::put($key, $now, self::HEARTBEAT_TTL_SECONDS);
                $this->lastWrittenAt[$key] = $now;
            } catch (Throwable) {
                // Worker diagnostics must not prevent otherwise healthy jobs from running.
            }
        }
    }

    /** @return array{state: string, lastSeenAt: string|null, ageSeconds: int|null} */
    public function status(string $connection, string $queue): array
    {
        $store = (string) config('cache.default');
        $driver = config("cache.stores.{$store}.driver");

        if (in_array($driver, ['array', 'null'], true)) {
            return ['state' => 'unknown', 'lastSeenAt' => null, 'ageSeconds' => null];
        }

        try {
            $lastSeen = Cache::get($this->key($connection, $queue));
        } catch (Throwable) {
            return ['state' => 'unknown', 'lastSeenAt' => null, 'ageSeconds' => null];
        }

        if (! is_numeric($lastSeen)) {
            return ['state' => 'not_detected', 'lastSeenAt' => null, 'ageSeconds' => null];
        }

        $timestamp = (int) $lastSeen;
        $ageSeconds = max(0, now()->timestamp - $timestamp);

        return [
            'state' => $ageSeconds <= self::RECENT_AFTER_SECONDS ? 'recently_seen' : 'stale',
            'lastSeenAt' => now()->setTimestamp($timestamp)->toIso8601String(),
            'ageSeconds' => $ageSeconds,
        ];
    }

    private function key(string $connection, string $queue): string
    {
        return implode(':', [
            'fieldops',
            'queue-worker',
            app()->environment(),
            $connection,
            $queue,
            'last-seen',
        ]);
    }
}
