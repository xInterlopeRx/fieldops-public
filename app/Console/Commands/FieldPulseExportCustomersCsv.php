<?php

namespace App\Console\Commands;

use App\Exceptions\FieldPulseApiException;
use App\Services\FieldPulse\FieldPulseApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Export FieldPulse Customers to a CSV in the exact header schema the existing
 * first-run CSV upload accepts (ADR-0039). Read-only against FieldPulse; the
 * produced file can be previewed and imported through the onboarding CSV path.
 */
#[Signature('fieldpulse:export-customers-csv {path : Destination CSV file path} {--limit=100 : Page size (max 100)}')]
#[Description('Export FieldPulse Customers to a CSV accepted by the first-run CSV upload. Read-only.')]
class FieldPulseExportCustomersCsv extends Command
{
    /** @var list<string> */
    private const HEADERS = [
        'Customer Id', 'Account Type', 'Account Status', 'First Name', 'Last Name', 'Company Name',
        'Display Name', 'Created', 'Email', 'Alternate Email', 'Phone Number', 'Alternate Phone Number',
        'Notes', 'Address Line 1', 'Address Line 2', 'City', 'State', 'Zip Code',
        'Use Separate Billing Address?', 'Billing Address Line 1', 'Billing Address Line 2',
        'Billing City', 'Billing State', 'Billing Zip Code', 'Secondary First Name', 'Secondary Last Name',
        'Secondary Email', 'Secondary Phone', 'Total Paid', 'Total Unpaid', 'Tags', 'Lead Source',
        'Assigned To [First Name]', 'Assigned To [Last Name]',
    ];

    public function handle(FieldPulseApiClient $client): int
    {
        $path = (string) $this->argument('path');
        $limit = min(100, max(1, (int) $this->option('limit')));

        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            $this->error('The destination directory is not writable: '.$directory);

            return self::FAILURE;
        }

        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error('Could not open the destination file for writing: '.$path);

            return self::FAILURE;
        }

        try {
            fputcsv($handle, self::HEADERS, ',', '"', '');

            $page = 1;
            $written = 0;

            while (true) {
                $result = $client->get('/customers', ['page' => $page, 'limit' => $limit, 'calculate_count' => 'true']);
                $records = $result['data'];

                foreach ($records as $record) {
                    if (! is_array($record)) {
                        continue;
                    }

                    fputcsv($handle, $this->row($record), ',', '"', '');
                    $written++;
                }

                $total = $result['total_count'];

                if ($records === [] || ($total !== null && $page * $limit >= $total)) {
                    break;
                }

                $page++;
            }
        } catch (FieldPulseApiException $exception) {
            $this->error('FieldPulse export failed: '.$exception->reason.' (HTTP '.$exception->httpStatus.')');

            return self::FAILURE;
        } finally {
            fclose($handle);
        }

        if ($written === 0) {
            @unlink($path);
            $this->error('FieldPulse returned no Customer records; no file was written.');

            return self::FAILURE;
        }

        $this->info('Wrote '.$written.' Customer row(s) to '.$path);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function row(array $record): array
    {
        $value = fn (string $key): string => $this->string($record[$key] ?? null) ?? '';

        return [
            $value('id'),
            $value('account_type'),
            $value('status'),
            $value('first_name'),
            $value('last_name'),
            $value('company_name'),
            $value('display_name'),
            $value('created_at'),
            $this->email($record['email'] ?? null),
            $this->email($record['alt_email'] ?? null),
            $value('phone'),
            $value('alt_phone'),
            $value('notes'),
            $value('address_1'),
            $value('address_2'),
            $value('city'),
            $value('state'),
            $value('zip_code'),
            $this->boolean($record['has_different_billing_address'] ?? null) ? 'Yes' : 'No',
            $value('billing_address_1'),
            $value('billing_address_2'),
            $value('billing_city'),
            $value('billing_state'),
            $value('billing_zip_code'),
            $value('secondary_first_name'),
            $value('secondary_last_name'),
            $this->email($record['secondary_email'] ?? null),
            $value('secondary_phone'),
            '',
            '',
            '',
            $value('lead_source'),
            '',
            '',
        ];
    }

    private function string(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * The CSV upload rejects a row whose email columns are present but malformed.
     * Emit only valid addresses so the bridge file is importable; drop others.
     */
    private function email(mixed $value): string
    {
        $string = $this->string($value);

        return $string !== null && filter_var($string, FILTER_VALIDATE_EMAIL) !== false ? $string : '';
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'Yes';
    }
}
