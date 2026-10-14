<?php

namespace App\Console\Commands;

use App\Services\QuickBooksCustomerImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportQuickBooksCustomers extends Command
{
    protected $signature = 'quickbooks:import-customers
                            {--replace-business-data : Clear local FieldOps business data before importing}
                            {--dry-run : Fetch and validate the QBO customer hierarchy without changing local data}
                            {--force : Skip the interactive confirmation}';

    protected $description = 'Replace local FieldOps business data with the connected QuickBooks sandbox customer dataset';

    public function handle(QuickBooksCustomerImportService $import): int
    {
        if (! $this->option('replace-business-data')) {
            $this->error('This import requires --replace-business-data because it replaces local business records.');

            return self::FAILURE;
        }

        if (! app()->environment('local')) {
            $this->error('This destructive dataset import is restricted to the local application environment.');

            return self::FAILURE;
        }

        if (config('database.default') !== 'pgsql' || config('services.quickbooks.environment') !== 'sandbox') {
            $this->error('This destructive dataset import requires PostgreSQL and the QuickBooks sandbox environment.');

            return self::FAILURE;
        }

        try {
            if ($this->option('dry-run')) {
                $counts = $import->preview();
                $this->components->info("Dry run validated {$counts['qbo_records']} QBO customer records: {$counts['customers']} parent customers and {$counts['sites']} child Sites. No local records were changed.");

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->confirm('Delete all local customers, contacts, sites, projects, jobs, visits, and assignments, then import every sandbox QuickBooks customer?')) {
                $this->components->info('Import cancelled; no local records were changed.');

                return self::SUCCESS;
            }

            $counts = $import->replaceBusinessData();
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('QuickBooks import failed. The local business-data reset was rolled back.');

            return self::FAILURE;
        }

        $this->components->info("Imported {$counts['customers']} parent customers, {$counts['sites']} child Sites, from {$counts['qbo_records']} QBO customer records.");
        $this->components->warn('QBO customer records without a FieldOps Customer link are now imported. Jobs, projects, and visits remain local and were cleared as requested.');

        return self::SUCCESS;
    }
}
