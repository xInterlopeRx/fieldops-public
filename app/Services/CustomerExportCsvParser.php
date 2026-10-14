<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

class CustomerExportCsvParser
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    private const MAX_ROWS = 1_000;

    /** @return array{sha256: string, records: list<array<string, mixed>>, preview: array<string, mixed>} */
    public function parse(UploadedFile $file): array
    {
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            throw new RuntimeException('csv_file_too_large');
        }

        $path = $file->getRealPath();
        $sha256 = is_string($path) ? hash_file('sha256', $path) : false;

        if (! is_string($path) || ! is_string($sha256)) {
            throw new RuntimeException('csv_file_unreadable');
        }

        $csv = new SplFileObject($path, 'r');
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl(',', '"', '');
        $headers = $csv->fgetcsv();

        if (! is_array($headers)) {
            throw new RuntimeException('csv_header_missing');
        }

        $headers = array_map(fn (mixed $header): string => trim(ltrim((string) $header, "\xEF\xBB\xBF")), $headers);
        $headerIndexes = $this->headerIndexes($headers);
        $errors = [];
        $seenIds = [];
        $records = [];
        $dataRowCount = 0;
        $invalidRowCount = 0;
        $siteCount = 0;
        $contactCount = 0;
        $assignedToRows = 0;
        $rowNumber = 1;

        while (! $csv->eof()) {
            $rawRow = $csv->fgetcsv();

            if ($rawRow === false) {
                break;
            }

            $rowNumber++;

            if ($rawRow === false || $rawRow === [null] || ! is_array($rawRow)
                || count(array_filter($rawRow, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            if ($rowNumber > self::MAX_ROWS + 1) {
                throw new RuntimeException('csv_row_limit_exceeded');
            }

            $dataRowCount++;

            if (count($rawRow) > count($headers)) {
                $extraValues = array_slice($rawRow, count($headers));

                if (array_filter($extraValues, fn (mixed $value): bool => trim((string) $value) !== '') !== []) {
                    $this->addError($errors, $invalidRowCount, $rowNumber, 'csv_column_count_mismatch');

                    continue;
                }
            }

            $row = array_pad(array_slice($rawRow, 0, count($headers)), count($headers), null);

            if (collect($row)->contains(fn (mixed $cell): bool => is_string($cell) && ! mb_check_encoding($cell, 'UTF-8'))) {
                $this->addError($errors, $invalidRowCount, $rowNumber, 'csv_encoding_invalid');

                continue;
            }

            $value = fn (string $header): ?string => isset($headerIndexes[$header])
                ? $this->value($row[$headerIndexes[$header]] ?? null)
                : null;

            $sourceId = $value('Customer Id');
            $companyName = $value('Company Name');
            $firstName = $value('First Name');
            $lastName = $value('Last Name');
            $displayName = $value('Display Name');
            $name = $displayName
                ?? $companyName
                ?? trim(implode(' ', array_filter([$firstName, $lastName])))
                ?: null;

            if ($sourceId === null) {
                $this->addError($errors, $invalidRowCount, $rowNumber, 'csv_customer_id_missing');

                continue;
            }

            if (isset($seenIds[$sourceId])) {
                $this->addError($errors, $invalidRowCount, $rowNumber, 'csv_customer_id_duplicate');

                continue;
            }
            $seenIds[$sourceId] = true;

            if ($name === null) {
                $this->addError($errors, $invalidRowCount, $rowNumber, 'csv_customer_name_missing');

                continue;
            }

            $email = $value('Email');
            $alternateEmail = $value('Alternate Email');
            $secondaryEmail = $value('Secondary Email');

            if (! $this->validEmail($email) || ! $this->validEmail($alternateEmail) || ! $this->validEmail($secondaryEmail)) {
                $this->addError($errors, $invalidRowCount, $rowNumber, 'csv_email_invalid');

                continue;
            }

            $lengths = [
                'customer_id' => [$sourceId, 255],
                'customer_name' => [$name, 255],
                'company_name' => [$companyName, 100],
                'first_name' => [$firstName, 255],
                'last_name' => [$lastName, 255],
                'email' => [$email, 255],
                'alternate_email' => [$alternateEmail, 255],
                'secondary_email' => [$secondaryEmail, 255],
                'alternate_phone' => [$value('Alternate Phone Number'), 64],
                'primary_phone' => [$value('Phone Number'), 255],
                'secondary_phone' => [$value('Secondary Phone'), 255],
                'secondary_first_name' => [$value('Secondary First Name'), 255],
                'secondary_last_name' => [$value('Secondary Last Name'), 255],
                'service_line1' => [$value('Address Line 1'), 500],
                'service_line2' => [$value('Address Line 2'), 500],
                'service_city' => [$value('City'), 255],
                'service_state' => [$value('State'), 255],
                'service_postal_code' => [$value('Zip Code'), 64],
                'billing_line1' => [$value('Billing Address Line 1'), 500],
                'billing_line2' => [$value('Billing Address Line 2'), 500],
                'billing_city' => [$value('Billing City'), 255],
                'billing_state' => [$value('Billing State'), 255],
                'billing_postal_code' => [$value('Billing Zip Code'), 64],
            ];

            if (collect($lengths)->contains(fn (array $field): bool => $field[0] !== null && Str::length($field[0]) > $field[1])) {
                $this->addError($errors, $invalidRowCount, $rowNumber, 'csv_field_too_long');

                continue;
            }

            $serviceAddress = $this->address($value, 'Address Line 1', 'Address Line 2', 'City', 'State', 'Zip Code');
            $billingAddress = $this->address(
                $value,
                'Billing Address Line 1',
                'Billing Address Line 2',
                'Billing City',
                'Billing State',
                'Billing Zip Code',
            );
            $primaryContact = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'alternate_email' => $alternateEmail,
                'phone' => $value('Phone Number'),
                'alternate_phone' => $value('Alternate Phone Number'),
            ];
            $secondaryContact = [
                'first_name' => $value('Secondary First Name'),
                'last_name' => $value('Secondary Last Name'),
                'email' => $secondaryEmail,
                'phone' => $value('Secondary Phone'),
            ];

            if ($this->contactHasData($primaryContact)) {
                $contactCount++;
            }

            if ($this->contactHasData($secondaryContact)) {
                $contactCount++;
            }

            if ($serviceAddress !== null) {
                $siteCount++;
            }

            if ($value('Assigned To [First Name]') !== null || $value('Assigned To [Last Name]') !== null) {
                $assignedToRows++;
            }

            $records[] = [
                'source_record_id' => $sourceId,
                'row' => $rowNumber,
                'customer' => [
                    'name' => $name,
                    'company_name' => $companyName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email ?? $alternateEmail,
                    'phone' => $value('Phone Number') ?? $value('Alternate Phone Number'),
                    'alternate_phone' => $value('Alternate Phone Number'),
                    'notes' => $value('Notes'),
                ],
                'primary_contact' => $primaryContact,
                'secondary_contact' => $secondaryContact,
                'service_address' => $serviceAddress,
                'billing_address' => $billingAddress,
            ];
        }

        if ($dataRowCount === 0) {
            throw new RuntimeException('csv_no_customer_rows');
        }

        return [
            'sha256' => $sha256,
            'records' => $records,
            'preview' => [
                'rows' => $dataRowCount,
                'customers' => count($records),
                'sites' => $siteCount,
                'contacts' => $contactCount,
                'invalidRowCount' => $invalidRowCount,
                'errors' => array_slice($errors, 0, 10),
                'assignedToUnmappedRows' => $assignedToRows,
                'financialColumnsIgnored' => true,
                'unmappedSourceFieldsIgnored' => [
                    'Account Type',
                    'Account Status',
                    'Created',
                    'Tags',
                    'Lead Source',
                    'Assigned To',
                ],
                'sha256' => $sha256,
            ],
        ];
    }

    /** @param list<string> $headers
     * @return array<string, int>
     */
    private function headerIndexes(array $headers): array
    {
        $indexes = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            if (isset($indexes[$header])) {
                throw new RuntimeException('csv_duplicate_headers');
            }

            $indexes[$header] = $index;
        }

        if (! isset($indexes['Customer Id'])) {
            throw new RuntimeException('csv_customer_id_header_missing');
        }

        $hasNameField = isset($indexes['Display Name']) || isset($indexes['Company Name'])
            || (isset($indexes['First Name']) && isset($indexes['Last Name']));

        if (! $hasNameField) {
            throw new RuntimeException('csv_customer_name_headers_missing');
        }

        return $indexes;
    }

    /** @param callable(string): ?string $value
     * @return array<string, ?string>|null
     */
    private function address(callable $value, string ...$headers): ?array
    {
        $keys = count($headers) === 5
            ? ['line1', 'line2', 'city', 'country_subdivision_code', 'postal_code']
            : [];
        $address = [];

        foreach ($headers as $index => $header) {
            $field = $keys[$index] ?? null;

            if ($field !== null) {
                $address[$field] = $value($header);
            }
        }

        $address = array_filter($address, fn (?string $field): bool => $field !== null);

        return $address === [] ? null : $address;
    }

    /** @param array<string, ?string> $contact */
    private function contactHasData(array $contact): bool
    {
        return collect($contact)->contains(fn (?string $field): bool => $field !== null);
    }

    private function validEmail(?string $email): bool
    {
        return $email === null || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function value(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    /** @param list<array{row: int, code: string}> $errors */
    private function addError(array &$errors, int &$errorCount, int $row, string $code): void
    {
        $errorCount++;

        if (count($errors) < 10) {
            $errors[] = ['row' => $row, 'code' => $code];
        }
    }
}
