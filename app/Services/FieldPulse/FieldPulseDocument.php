<?php

namespace App\Services\FieldPulse;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * "Our FieldPulse document": the aggregated FieldPulse data captured via the
 * API, written to a single XLSX workbook suitable for upload into FieldOps.
 *
 * One worksheet per entity. Both the writer (document generation) and the
 * reader (the FieldPulse uploader) use the same column definitions here so the
 * two never drift.
 */
class FieldPulseDocument
{
    /**
     * Sheet name => ordered column keys. The first row of each sheet is the
     * header, written verbatim from these keys.
     *
     * @var array<string, list<string>>
     */
    public const SHEETS = [
        'Customers' => [
            'fieldpulse_id', 'display_name', 'company_name', 'first_name', 'last_name',
            'email', 'phone', 'alt_phone', 'mobile_phone', 'fax', 'website',
            'address', 'notes', 'account_type', 'status', 'lead_source', 'assigned_to', 'created_at',
            'secondary_first_name', 'secondary_last_name', 'secondary_email', 'secondary_phone',
            'metadata_json',
        ],
        'Locations' => [
            'fieldpulse_id', 'customer_fieldpulse_id', 'title', 'address',
            'is_main_location', 'is_primary_location', 'latitude', 'longitude',
            'metadata_json',
        ],
        'Projects' => [
            'fieldpulse_id', 'customer_fieldpulse_id', 'project_number', 'name',
            'notes', 'status', 'metadata_json',
        ],
        'Jobs' => [
            'fieldpulse_id', 'customer_fieldpulse_id', 'location_fieldpulse_id',
            'title', 'subtitle', 'status', 'job_notes', 'field_notes', 'metadata_json',
        ],
        'Notes' => [
            'source_record_id', 'customer_fieldpulse_id', 'site_fieldpulse_id',
            'kind', 'body', 'occurred_at', 'metadata_json',
        ],
        'Contacts' => [
            'fieldpulse_contact_id', 'customer_fieldpulse_id', 'contact_type',
            'first_name', 'last_name', 'email', 'phone', 'mobile_phone', 'alt_phone',
            'notes', 'created_at', 'metadata_json',
        ],
    ];

    /**
     * Write the workbook.
     *
     * @param  array<string, list<array<string, mixed>>>  $data  Sheet name => rows.
     */
    public function write(string $path, array $data): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach (self::SHEETS as $sheetName => $columns) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetName);

            foreach ($columns as $index => $column) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $column);
            }

            $rowNumber = 2;

            foreach ($data[$sheetName] ?? [] as $row) {
                foreach ($columns as $index => $column) {
                    $value = $row[$column] ?? null;

                    if ($value !== null && $value !== '') {
                        $sheet->setCellValue(
                            Coordinate::stringFromColumnIndex($index + 1).$rowNumber,
                            is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES),
                        );
                    }
                }

                $rowNumber++;
            }

            $sheet->freezePane('A2');
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * Read the workbook back into sheet => list of header-keyed rows.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('fieldpulse_document_unreadable');
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $result = [];

        foreach (self::SHEETS as $sheetName => $columns) {
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if ($sheet === null) {
                if ($sheetName === 'Projects') {
                    $result[$sheetName] = [];

                    continue;
                }

                throw new RuntimeException('fieldpulse_document_sheet_missing');
            }

            $rows = $sheet->toArray(null, true, false, false);
            $header = array_shift($rows);

            if (! is_array($header)) {
                $result[$sheetName] = [];

                continue;
            }

            $header = array_map(static fn (mixed $value): string => trim((string) $value), $header);

            if ($header !== $columns) {
                throw new RuntimeException('fieldpulse_document_header_mismatch');
            }

            $records = [];

            foreach ($rows as $row) {
                $record = [];
                $hasValue = false;

                foreach ($columns as $index => $column) {
                    $value = $row[$index] ?? null;
                    $value = is_scalar($value) ? trim((string) $value) : '';

                    if ($value !== '') {
                        $hasValue = true;
                    }

                    $record[$column] = $value;
                }

                if ($hasValue) {
                    $records[] = $record;
                }
            }

            $result[$sheetName] = $records;
        }

        $spreadsheet->disconnectWorksheets();

        return $result;
    }
}
