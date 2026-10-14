<?php

namespace App\Console\Commands;

use App\Services\ResetLocalBusinessDataForQuickBooksImportTest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('quickbooks:prepare-initial-import-test-data {--source=quickbooks_api : Initial Customer/Site source (quickbooks_api or csv_upload)} {--dry-run : Show the local reset scope without changing records} {--force : Apply the local-only reset and reopen first-run onboarding}')]
#[Description('Prepare the local PostgreSQL database for a clean QuickBooks initial-import test while preserving users and integrations.')]
class PrepareQuickBooksInitialImportTestData extends Command
{
    public function handle(ResetLocalBusinessDataForQuickBooksImportTest $reset): int
    {
        try {
            $source = (string) $this->option('source');
            $preview = $reset->preview($source);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $target = $preview['importMode'] === 'csv_upload'
            ? 'local PostgreSQL; local-only CSV import mode.'
            : 'local PostgreSQL; QuickBooks sandbox.';
        $this->components->info('Target: '.$target);
        $this->components->info("Preserved users: {$preview['usersPreserved']}; eligible Admin account: {$preview['adminAccountsPreserved']}; integration connections: {$preview['integrationConnectionsPreserved']}.");
        $this->line('Business and first-import test rows in scope:');

        foreach ($preview['tables'] as $table => $count) {
            $this->line("  {$table}: {$count}");
        }

        $this->warn('BWS/QBO connections, Graph configuration, all staff accounts, roles, audit history, invitations, and stored attachment bytes are preserved. Attachment metadata, pending jobs, and Graph outbox rows must be empty.');

        if ($this->option('dry-run')) {
            $this->components->info('Dry run only. No local records were changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->components->error('No records were changed. Re-run with --force only after reviewing this exact scope.');

            return self::FAILURE;
        }

        try {
            $result = $reset->reset((string) $this->option('source'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Local business records were cleared. Existing users and integration connections were preserved.');
        $this->components->info('First-run onboarding is enabled; Customer/Site import is pending and invitations remain locked.');
        $this->components->info('The existing Admin is now recorded as the first-admin claim actor.');
        $this->components->info('No QuickBooks, BWS, Microsoft Graph, or attachment-storage writes were made.');

        return self::SUCCESS;
    }
}
