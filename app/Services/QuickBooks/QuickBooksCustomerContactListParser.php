<?php

namespace App\Services\QuickBooks;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads the QuickBooks "Customer Contact List" export (an .xlsx workbook with
 * a title banner and a header row) into normalized Customer records.
 *
 * The report has no stable QuickBooks identifier, so each record carries a
 * synthetic source key derived from its own content (name + email/phone).
 * Records are never matched by name against existing data.
 */
class QuickBooksCustomerContactListParser
{
    private const SYNTHETIC_PREFIX = 'QBX-';

    /**
     * @return array{records: list<array<string, mixed>>, preview: array<string, mixed>}
     */
    public function parse(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('quickbooks_file_unreadable');
        }

        $spreadsheet = IOFactory::createReaderForFile($path)->load($path);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        $headerIndex = $this->findHeaderRow($rows);

        if ($headerIndex === null) {
            throw new RuntimeException('quickbooks_header_missing');
        }

        $columns = $this->mapColumns($rows[$headerIndex]);
        $records = [];
        $seen = [];
        $withBilling = 0;
        $withShipping = 0;
        $duplicates = 0;

        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $name = $this->value($row, $columns['customer'] ?? null);

            if ($name === null) {
                continue;
            }

            $email = $this->value($row, $columns['email'] ?? null);
            $phone = $this->phone($this->value($row, $columns['phone'] ?? null));
            $fullName = $this->value($row, $columns['full_name'] ?? null);
            $billing = $this->address($this->value($row, $columns['billing'] ?? null));
            $shipping = $this->address($this->value($row, $columns['shipping'] ?? null));

            if ($billing !== null) {
                $withBilling++;
            }

            if ($shipping !== null) {
                $withShipping++;
            }

            [$firstName, $lastName] = $this->splitName($fullName);
            $sourceId = $this->syntheticId($name, $email, $phone);

            if (isset($seen[$sourceId])) {
                $duplicates++;

                continue;
            }

            $seen[$sourceId] = true;
            $records[] = [
                'source_record_id' => $sourceId,
                'name' => $name,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'billing_address' => $billing,
                'shipping_address' => $shipping,
            ];
        }

        if ($records === []) {
            throw new RuntimeException('quickbooks_no_customer_rows');
        }

        return [
            'records' => $records,
            'preview' => [
                'rows' => count($rows) - $headerIndex - 1,
                'customers' => count($records),
                'billingAddresses' => $withBilling,
                'shippingAddresses' => $withShipping,
                'duplicateRows' => $duplicates,
            ],
        ];
    }

    /** @param list<list<mixed>> $rows */
    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $cells = array_map(static fn (mixed $cell): string => strtolower(trim((string) $cell)), $row);

            if (in_array('customer', $cells, true) && in_array('email', $cells, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $key = strtolower(trim((string) $cell));

            $column = match ($key) {
                'customer' => 'customer',
                'email' => 'email',
                'phone numbers', 'phone number', 'phone' => 'phone',
                'full name' => 'full_name',
                'billing address' => 'billing',
                'shipping address' => 'shipping',
                default => null,
            };

            if ($column !== null && ! isset($map[$column])) {
                $map[$column] = $index;
            }
        }

        if (! isset($map['customer'], $map['email'])) {
            throw new RuntimeException('quickbooks_header_incomplete');
        }

        return $map;
    }

    /** @param list<mixed> $row */
    private function value(array $row, ?int $index): ?string
    {
        if ($index === null || ! array_key_exists($index, $row)) {
            return null;
        }

        $value = is_scalar($row[$index]) ? trim((string) $row[$index]) : '';

        return $value === '' ? null : $value;
    }

    private function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Values look like "Phone: (540) 430-1192" or "Mobile: (361) 319-1081".
        $phone = preg_replace('/^[A-Za-z ]+:/', '', $value);
        $phone = trim((string) $phone);

        return $phone === '' ? null : $phone;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function splitName(?string $fullName): array
    {
        if ($fullName === null) {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];

        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /** @return array<string, string>|null */
    private function address(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));

        if ($lines === []) {
            return null;
        }

        $last = array_pop($lines);
        $postal = null;
        $state = null;

        if (preg_match('/(\d{5}(?:-\d{4})?)\s*$/', $last, $matches) === 1) {
            $postal = $matches[1];
            $last = trim(substr($last, 0, -strlen($matches[0])));
        }

        $tokens = $last === '' ? [] : (preg_split('/\s+/', $last) ?: []);

        if (count($tokens) > 1) {
            $state = array_pop($tokens);
            $city = implode(' ', $tokens);
        } else {
            $city = $last;
        }

        $address = [
            'line1' => $lines[0] ?? null,
            'line2' => $lines[1] ?? null,
            'city' => $city !== '' ? $city : null,
            'country_subdivision_code' => $state,
            'postal_code' => $postal,
        ];

        return array_filter($address, static fn (mixed $part): bool => $part !== null && $part !== '');
    }

    private function syntheticId(string $name, ?string $email, ?string $phone): string
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($name)).'|'.($email ?? '').'|'.($phone ?? ''));

        return self::SYNTHETIC_PREFIX.substr(sha1($normalized), 0, 12);
    }
}
