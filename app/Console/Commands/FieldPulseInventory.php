<?php

namespace App\Console\Commands;

use App\Exceptions\FieldPulseApiException;
use App\Services\FieldPulse\FieldPulseApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Read-only FieldPulse schema probe (ADR-0039). Prints the shape of a small
 * page from each endpoint — key paths and value types only, never values — so
 * the import mapping can be finalized. It issues GET requests only.
 */
#[Signature('fieldpulse:inventory {--endpoint=all : customers|locations|jobs|comments|projects|all}')]
#[Description('Probe FieldPulse record schemas with read-only GET requests. Local only; prints keys and types, never values.')]
class FieldPulseInventory extends Command
{
    /** @var array<string, array{path: string, query: array<string, mixed>}> */
    private const PROBES = [
        'customers' => ['path' => '/customers', 'query' => ['limit' => 1, 'calculate_count' => 'true']],
        'locations' => ['path' => '/locations', 'query' => ['object_type' => 'customer', 'limit' => 1, 'calculate_count' => 'true']],
        'jobs' => ['path' => '/jobs', 'query' => ['limit' => 1, 'calculate_count' => 'true']],
        'comments' => ['path' => '/comments', 'query' => ['object_type' => 'job', 'limit' => 1, 'calculate_count' => 'true']],
        'projects' => ['path' => '/projects', 'query' => ['limit' => 1, 'calculate_count' => 'true']],
    ];

    private const MAX_DEPTH = 4;

    private const MAX_LINES = 200;

    public function handle(FieldPulseApiClient $client): int
    {
        if (! app()->isLocal()) {
            $this->error('The FieldPulse inventory probe is available only when APP_ENV=local.');

            return self::FAILURE;
        }

        $endpoint = (string) $this->option('endpoint');
        $probes = $endpoint === 'all' ? self::PROBES : array_intersect_key(self::PROBES, [$endpoint => true]);

        if ($probes === []) {
            $this->error('Unknown endpoint. Use one of: '.implode(', ', [...array_keys(self::PROBES), 'all']).'.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($probes as $name => $probe) {
            try {
                $result = $client->get($probe['path'], $probe['query']);
            } catch (FieldPulseApiException $exception) {
                $failed = true;
                $this->warn(sprintf('%s %s: %s (HTTP %d)', $name, $probe['path'], $exception->reason, $exception->httpStatus));

                continue;
            }

            $record = $this->firstRecord($result['data']);
            $this->line(sprintf(
                '%s %s — total_count=%s',
                $name,
                $probe['path'],
                $result['total_count'] === null ? 'n/a' : (string) $result['total_count'],
            ));

            if ($record === null) {
                $this->line('  (no records returned)');
            } else {
                $lines = [];
                $this->describe($record, '', 0, $lines);

                foreach ($lines as $line) {
                    $this->line('  '.$line);
                }
            }

            $this->newLine();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<mixed> $data */
    private function firstRecord(array $data): mixed
    {
        if ($data === []) {
            return null;
        }

        // List responses are arrays of records; single-object responses are objects.
        return array_is_list($data) ? ($data[0] ?? null) : $data;
    }

    /** @param list<string> $lines */
    private function describe(mixed $value, string $prefix, int $depth, array &$lines): void
    {
        if (count($lines) >= self::MAX_LINES) {
            return;
        }

        if (! is_array($value)) {
            $lines[] = ($prefix === '' ? '(scalar)' : $prefix).' : '.gettype($value);

            return;
        }

        if ($value === []) {
            $lines[] = ($prefix === '' ? '(empty)' : $prefix).' : array(empty)';

            return;
        }

        if ($depth >= self::MAX_DEPTH) {
            $lines[] = ($prefix === '' ? '(nested)' : $prefix).' : array';

            return;
        }

        foreach ($value as $key => $item) {
            if (count($lines) >= self::MAX_LINES) {
                $lines[] = '… (truncated)';

                return;
            }

            $path = is_int($key) ? $prefix.'[]' : ($prefix === '' ? (string) $key : $prefix.'.'.$key);
            $this->describe($item, $path, $depth + 1, $lines);
        }
    }
}
