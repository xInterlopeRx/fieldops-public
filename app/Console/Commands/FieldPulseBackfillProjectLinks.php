<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FieldPulse\FieldPulseProjectBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FieldPulseBackfillProjectLinks extends Command
{
    protected $signature = 'fieldpulse:backfill-project-links
        {document : The retained FieldPulse XLSX document containing Projects and Jobs}
        {--execute : Create missing FieldPulse Projects and link existing Jobs; default is read-only}
        {--actor-email= : Active Admin email recorded in backfill audits}';

    protected $description = 'Backfill existing FieldPulse Job-to-Project associations from a retained document';

    public function handle(FieldPulseProjectBackfillService $backfill): int
    {
        $path = (string) $this->argument('document');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The FieldPulse document path is missing or unreadable.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $actor = null;

        if ($execute) {
            $email = trim((string) $this->option('actor-email'));
            $actor = User::query()->where('status', 'active')->where('email', $email)->first();

            if (! $actor?->can('integrations.manage')) {
                $this->error('An active Admin with integrations.manage is required to execute the audited backfill.');

                return self::FAILURE;
            }
        }

        try {
            $result = $execute
                ? $backfill->execute($path, $actor)
                : $backfill->preview($path);
        } catch (Throwable $exception) {
            $this->error('FieldPulse Project-link backfill stopped safely: '.$this->safeErrorCode($exception));

            return self::FAILURE;
        }

        $this->table(['Backfill inventory', 'Value'], [
            ['Environment', app()->environment()],
            ['Database', DB::connection()->getDatabaseName()],
            ['Mode', $result['executed'] ? 'executed' : ($execute ? 'blocked; no writes applied' : 'read-only dry run')],
            ['Projects in document', $result['source_projects']],
            ['Projects to create', $result['projects_to_create']],
            ['Projects already present', $result['projects_existing']],
            ['Projects skipped; source Customer missing locally', $result['projects_skipped_missing_owner']],
            ['Projects created', $result['projects_created']],
            ['Jobs with Project reference', $result['jobs_with_project_reference']],
            ['Jobs to link', $result['jobs_to_link']],
            ['Jobs linked', $result['jobs_linked']],
            ['Jobs already linked correctly', $result['jobs_already_linked']],
            ['Jobs not present locally', $result['jobs_missing']],
            ['Missing Jobs skipped with orphan Projects', $result['jobs_skipped_missing_project_owner']],
            ['Jobs with unmatched Project reference', $result['jobs_unmatched_project_reference']],
            ['Archived Jobs skipped', $result['jobs_archived']],
            ['Conflicts', $result['conflict_count']],
        ]);

        if ($result['conflicts'] !== []) {
            $this->table(['Resource', 'Source ID', 'Failure code'], array_map(
                static fn (array $conflict): array => [
                    $conflict['resource_type'],
                    $conflict['source_record_id'],
                    $conflict['failure_code'],
                ],
                $result['conflicts'],
            ));
            $this->error('No backfill changes were applied because preflight found conflicts.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->info('Read-only dry run. Pass --execute with --actor-email to apply this backfill.');
        }

        return self::SUCCESS;
    }

    private function safeErrorCode(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/\A[a-z0-9_]{1,80}\z/D', $message) === 1
            ? $message
            : 'fieldpulse_project_backfill_failed';
    }
}
