<?php

namespace App\Console\Commands;

use App\Services\FieldPulse\FieldPulseDocument;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Operator-only command: rebuild "our FieldPulse document" from the full
 * FieldPulse CSV account export (the provider's own archive, generated after
 * API access ended). The output workbook is uploaded through the first-run
 * FieldPulse document panel exactly like a captured document.
 *
 * Reads only; writes the workbook to the requested path. Accounting-domain
 * exports (invoices, estimates, payments, catalog items) and file/team exports
 * are deliberately not converted - they stay preserved in the archive.
 */
class FieldPulseBuildDocumentFromArchive extends Command
{
    protected $signature = 'fieldpulse:build-document {source} {output}';

    protected $description = 'Build the uploadable FieldPulse document from the full CSV account export';

    public function handle(FieldPulseDocument $document): int
    {
        $source = rtrim((string) $this->argument('source'), '/');
        $output = (string) $this->argument('output');

        if (! is_dir($source)) {
            $this->error("Source directory not found: {$source}");

            return self::FAILURE;
        }

        $customers = $this->readCsv($source, 'customers.csv');
        $contactRows = $this->readCsv($source, 'customer_contacts.csv');
        $locations = $this->readCsv($source, 'locations.csv');
        $jobs = $this->readCsv($source, 'jobs.csv');
        $projects = $this->readCsv($source, 'projects.csv');
        $recurring = $this->readCsv($source, 'recurring_jobs.csv');
        $comments = $this->readCsv($source, 'comments.csv');

        $customerOrder = array_column($customers, 'Customer #');
        $projectsByNo = [];
        foreach ($projects as $project) {
            $projectNo = trim($project['Project #']);

            if ($projectNo !== '') {
                $projectsByNo[$projectNo] = $project;
            }
        }
        $recurringByNo = [];
        foreach ($recurring as $row) {
            $recurringByNo[$row['Recurring Job #']] = $row;
        }

        // Location IDs are synthesized deterministically per customer, in CSV
        // row order, because the export does not carry location identifiers.
        $locationByName = [];    // customerNo => [lowercased name => id]
        $locationByAddress = []; // customerNo => [lowercased address line 1 => id]
        $customerLocationSeq = [];
        $customerContactSeq = [];

        foreach ($customerOrder as $customerNo) {
            $customerContactSeq[$customerNo] = 0;
        }

        $importableCustomerNos = array_flip($customerOrder);
        $customersWithLocations = [];
        $orphanLocationsSkipped = 0;

        $contactsSheet = [];
        foreach ($customerOrder as $customerNo) {
            foreach ($contactRows as $contact) {
                if ($contact['Customer #'] !== $customerNo) {
                    continue;
                }

                $customerContactSeq[$customerNo]++;
                $seq = $customerContactSeq[$customerNo];

                $contactsSheet[] = [
                    'fieldpulse_contact_id' => sprintf('CSV-CON-%s-%02d', $customerNo, $seq),
                    'customer_fieldpulse_id' => $customerNo,
                    'contact_type' => $seq === 1 ? 'secondary' : 'additional_'.$seq,
                    'first_name' => $contact['First Name'],
                    'last_name' => $contact['Last Name'],
                    'email' => $contact['Email'],
                    'phone' => $contact['Phone'],
                    'mobile_phone' => '',
                    'alt_phone' => '',
                    'notes' => $contact['Notes'],
                    'created_at' => $contact['Created'],
                    'metadata_json' => $this->json([
                        'archive_created_at' => $contact['Created'],
                    ]),
                ];
            }
        }

        $customersSheet = [];
        foreach ($customers as $row) {
            $customerNo = $row['Customer #'];
            $customersSheet[] = [
                'fieldpulse_id' => $customerNo,
                'display_name' => $row['Display Name'],
                'company_name' => $row['Company Name'],
                'first_name' => $row['First Name'],
                'last_name' => $row['Last Name'],
                'email' => $row['Email'],
                'phone' => $row['Phone'],
                'alt_phone' => $row['Alt Phone'],
                'mobile_phone' => $row['Mobile Phone'],
                'fax' => '',
                'website' => $row['Website'],
                'address' => $this->composeAddress($row['Address 1'], $row['Address 2'], $row['City'], $row['State'], $row['Zip']),
                'notes' => $row['Notes'],
                'account_type' => $row['Account Type'],
                'status' => '',
                'lead_source' => '',
                'assigned_to' => '',
                'created_at' => $row['Created'],
                'secondary_first_name' => '',
                'secondary_last_name' => '',
                'secondary_email' => '',
                'secondary_phone' => '',
                'metadata_json' => $this->json(array_filter([
                    'alt_email' => $row['Alt Email'] ?? '',
                    'billing_address' => $this->composeAddress($row['Billing Address 1'], $row['Billing Address 2'], $row['Billing City'], $row['Billing State'], $row['Billing Zip']),
                    'parent_customer_no' => $row['Parent Customer #'] ?? '',
                ], static fn (string $value): bool => $value !== '')),
            ];
        }

        $locationsSheet = [];
        foreach ($locations as $row) {
            $customerNo = $row['Customer #'];

            // Locations whose customer is absent from the export (archived or
            // deleted in FieldPulse) cannot be imported - the owner is required.
            if (! isset($importableCustomerNos[$customerNo])) {
                $orphanLocationsSkipped++;

                continue;
            }

            $customersWithLocations[$customerNo] = true;
            $customerLocationSeq[$customerNo] = ($customerLocationSeq[$customerNo] ?? 0) + 1;
            $id = sprintf('CSV-LOC-%s-%03d', $customerNo, $customerLocationSeq[$customerNo]);
            $locationIds[$customerNo][] = $id;

            $nameKey = mb_strtolower(trim($row['Location Name']));
            $addressKey = mb_strtolower(trim($row['Address 1']));
            $locationByName[$customerNo][$nameKey] ??= $id;
            $locationByAddress[$customerNo][$addressKey] ??= $id;

            $locationsSheet[] = [
                'fieldpulse_id' => $id,
                'customer_fieldpulse_id' => $customerNo,
                'title' => $row['Location Name'],
                'address' => $this->composeAddress($row['Address 1'], $row['Address 2'], $row['City'], $row['State'], $row['Zip']),
                'is_main_location' => trim($row['Main Location']) === 'Yes' ? 'true' : '',
                'is_primary_location' => trim($row['Main Location']) === 'Yes' ? 'true' : '',
                'latitude' => '',
                'longitude' => '',
                'metadata_json' => $this->json(array_filter([
                    'notes' => $row['Notes'],
                    'address_1' => $row['Address 1'],
                    'address_2' => $row['Address 2'],
                    'city' => $row['City'],
                    'state' => $row['State'],
                    'zip' => $row['Zip'],
                    'main_location' => trim($row['Main Location']),
                ], static fn (string $value): bool => $value !== '')),
            ];
        }

        $projectsSheet = [];
        $importableProjectNos = [];
        $projectsWithoutExportCustomerSkipped = 0;

        foreach ($projects as $row) {
            $projectNo = trim($row['Project #']);
            $customerNo = trim($row['Customer #']);

            if ($projectNo === '') {
                continue;
            }

            // The import requires an existing Customer. Keep orphan Project rows
            // in the original archive rather than creating an ownerless Project.
            if (! isset($importableCustomerNos[$customerNo])) {
                $projectsWithoutExportCustomerSkipped++;

                continue;
            }

            $projectsSheet[] = [
                'fieldpulse_id' => $projectNo,
                'customer_fieldpulse_id' => $customerNo,
                'project_number' => $projectNo,
                'name' => trim($row['Name']) !== '' ? $row['Name'] : 'Project '.$projectNo,
                'notes' => $row['Notes'],
                'status' => $row['Status'],
                'metadata_json' => $this->json(array_filter([
                    'description' => $row['Description'],
                    'start_at' => $row['Start'],
                    'end_at' => $row['End'],
                    'created_at' => $row['Created'],
                ], static fn (string $value): bool => trim($value) !== '')),
            ];
            $importableProjectNos[$projectNo] = true;
        }

        $jobsSheet = [];
        $notesSheet = [];
        $jobByNo = [];

        $resolution = ['direct' => 0, 'project' => 0, 'recurring' => 0, 'none' => 0];
        $locationMatch = ['name' => 0, 'address' => 0, 'none' => 0];
        $jobStatuses = [];
        $projectLinks = ['matched' => 0, 'unmatched' => 0, 'customer_mismatch' => 0];
        $tasksSkipped = 0;
        $tasksWithCustomer = 0;
        $customerlessJobsSkipped = 0;
        $noSiteJobsSkipped = 0;
        $excludedJobs = [];

        foreach ($jobs as $row) {
            // FieldPulse Tasks are calendar/administrative items (time-off,
            // reminders, schedule holds) - not customer work - and are not
            // imported. They stay preserved in the archive.
            if (trim($row['Type']) === 'Task') {
                $tasksSkipped++;
                if (trim($row['Customer #']) !== '') {
                    $tasksWithCustomer++;
                }

                continue;
            }

            $jobNo = $row['Job #'];
            $customerNo = trim($row['Customer #']);
            $source = 'direct';

            $projectNo = trim($row['Project #']);

            if ($customerNo === '' && $projectNo !== '' && isset($projectsByNo[$projectNo])) {
                $customerNo = trim($projectsByNo[$projectNo]['Customer #']);
                $source = 'project';
            }

            if ($customerNo === '' && trim($row['Recurring Job #']) !== '' && isset($recurringByNo[$row['Recurring Job #']])) {
                $customerNo = trim($recurringByNo[$row['Recurring Job #']]['Customer #']);
                $source = 'recurring';
            }

            if ($customerNo === '') {
                $source = 'none';
            }
            $resolution[$source]++;

            // Jobs without a resolvable customer are incomplete tickets -
            // preserved in the archive, not imported.
            if ($customerNo === '') {
                $customerlessJobsSkipped++;
                $excludedJobs[] = $jobNo.' (no customer)';

                continue;
            }

            // Jobs whose customer has no site cannot be placed (work orders
            // require a site) - preserved in the archive, not imported.
            if (! isset($customersWithLocations[$customerNo])) {
                $noSiteJobsSkipped++;
                $excludedJobs[] = $jobNo.' (customer has no site)';

                continue;
            }

            $locationId = '';
            $locationRaw = trim($row['Location']);
            if ($locationRaw !== '' && $customerNo !== '' && isset($locationByName[$customerNo])) {
                $nameKey = mb_strtolower($locationRaw);
                if (isset($locationByName[$customerNo][$nameKey])) {
                    $locationId = $locationByName[$customerNo][$nameKey];
                    $locationMatch['name']++;
                } else {
                    $addressKey = mb_strtolower(explode(',', $locationRaw)[0]);
                    if (isset($locationByAddress[$customerNo][$addressKey])) {
                        $locationId = $locationByAddress[$customerNo][$addressKey];
                        $locationMatch['address']++;
                    } else {
                        $locationMatch['none']++;
                    }
                }
            } elseif ($locationRaw !== '') {
                $locationMatch['none']++;
            }

            $statusMap = [
                'new' => 'created',
                'in_progress' => 'in_progress',
                'completed' => 'completed',
                'pending' => 'pending',
                'canceled' => 'canceled',
                'cancelled' => 'canceled',
                'scheduled' => 'scheduled',
            ];
            $rawStatus = str_replace(' ', '_', mb_strtolower(trim($row['Status'])));
            $status = $statusMap[$rawStatus] ?? 'created';
            $jobStatuses[$rawStatus] = ($jobStatuses[$rawStatus] ?? 0) + 1;

            if ($projectNo !== '') {
                $projectRow = $projectsByNo[$projectNo] ?? null;

                if (! isset($importableProjectNos[$projectNo]) || $projectRow === null) {
                    $projectLinks['unmatched']++;
                } elseif (trim($projectRow['Customer #']) !== $customerNo) {
                    $projectLinks['customer_mismatch']++;
                } else {
                    $projectLinks['matched']++;
                }
            }

            $jobsSheet[] = [
                'fieldpulse_id' => $jobNo,
                'customer_fieldpulse_id' => $customerNo,
                'location_fieldpulse_id' => $locationId,
                'title' => trim($row['Job Type']) !== '' ? $row['Job Type'] : (trim($row['Subtitle']) !== '' ? $row['Subtitle'] : $row['Type']),
                'subtitle' => $row['Subtitle'],
                'status' => $status,
                'job_notes' => $row['Notes'],
                'field_notes' => '',
                'metadata_json' => $this->json(array_filter([
                    'archive_type' => $row['Type'],
                    'job_type' => $row['Job Type'],
                    'start_at' => $row['Start'],
                    'end_at' => $row['End'],
                    'completed_at' => $row['Completed'],
                    'location_name' => $locationRaw,
                    'recurring_job_no' => $row['Recurring Job #'],
                    'project_no' => $projectNo,
                    'customer_source' => $source,
                ], static fn (string $value): bool => $value !== '')),
            ];

            $jobByNo[$jobNo] = ['customer' => $customerNo, 'location' => $locationId];

            if (trim($row['Notes']) !== '' && $customerNo !== '') {
                $notesSheet[] = [
                    'source_record_id' => 'job_note:'.$jobNo,
                    'customer_fieldpulse_id' => $customerNo,
                    'site_fieldpulse_id' => $locationId,
                    'kind' => 'job_note',
                    'body' => $row['Notes'],
                    'occurred_at' => $row['Created'],
                    'metadata_json' => $this->json(array_filter([
                        'job_number' => $jobNo,
                        'job_type' => $row['Job Type'],
                    ], static fn (string $value): bool => $value !== '')),
                ];
            }
        }

        foreach ($customers as $row) {
            if (trim($row['Notes']) === '') {
                continue;
            }

            $notesSheet[] = [
                'source_record_id' => 'customer_note:'.$row['Customer #'],
                'customer_fieldpulse_id' => $row['Customer #'],
                'site_fieldpulse_id' => '',
                'kind' => 'customer_note',
                'body' => $row['Notes'],
                'occurred_at' => $row['Created'],
                'metadata_json' => '{}',
            ];
        }

        $commentKinds = ['job' => 0, 'project' => 0, 'skipped' => 0];

        foreach ($comments as $row) {
            $subject = trim($row['On']);
            $recordNo = trim($row['Record #']);

            if ($subject === 'Job' && isset($jobByNo[$recordNo])) {
                $commentKinds['job']++;
                $notesSheet[] = [
                    'source_record_id' => 'comment:'.$recordNo,
                    'customer_fieldpulse_id' => $jobByNo[$recordNo]['customer'],
                    'site_fieldpulse_id' => $jobByNo[$recordNo]['location'],
                    'kind' => 'comment',
                    'body' => $row['Comment'],
                    'occurred_at' => $row['Created'],
                    'metadata_json' => $this->json(array_filter([
                        'author' => $row['Author'],
                        'job_number' => $recordNo,
                    ], static fn (string $value): bool => $value !== '')),
                ];

                continue;
            }

            if ($subject === 'Project' && isset($projectsByNo[$recordNo])) {
                $commentKinds['project']++;
                $notesSheet[] = [
                    'source_record_id' => 'comment:project:'.$recordNo,
                    'customer_fieldpulse_id' => trim($projectsByNo[$recordNo]['Customer #']),
                    'site_fieldpulse_id' => '',
                    'kind' => 'comment',
                    'body' => $row['Comment'],
                    'occurred_at' => $row['Created'],
                    'metadata_json' => $this->json(array_filter([
                        'author' => $row['Author'],
                        'project_number' => $recordNo,
                    ], static fn (string $value): bool => $value !== '')),
                ];

                continue;
            }

            $commentKinds['skipped']++;
        }

        $data = [
            'Customers' => $customersSheet,
            'Locations' => $locationsSheet,
            'Projects' => $projectsSheet,
            'Jobs' => $jobsSheet,
            'Notes' => $notesSheet,
            'Contacts' => $contactsSheet,
        ];

        $document->write($output, $data);

        $this->info('FieldPulse document written: '.$output);
        $this->table(['Sheet', 'Rows'], [
            ['Customers', count($customersSheet)],
            ['Contacts', count($contactsSheet)],
            ['Locations', count($locationsSheet)],
            ['Projects', count($projectsSheet)],
            ['Jobs', count($jobsSheet)],
            ['Notes', count($notesSheet)],
        ]);
        $this->line('Job customer resolution: direct='.($resolution['direct']).
            ' project='.($resolution['project']).
            ' recurring='.($resolution['recurring']).
            ' unresolved='.($resolution['none']));
        $this->line('Project links: matched='.$projectLinks['matched'].
            ' unmatched='.$projectLinks['unmatched'].
            ' customer-mismatches='.$projectLinks['customer_mismatch']);
        $this->line('Job location match: name='.($locationMatch['name']).
            ' address='.($locationMatch['address']).
            ' none='.($locationMatch['none']));
        $this->line('Raw job statuses: '.json_encode($jobStatuses));
        $this->line('Comments: job='.($commentKinds['job']).
            ' project='.($commentKinds['project']).
            ' skipped='.($commentKinds['skipped']).
            ' (invoice/sub-task comments stay preserved in the archive)');
        $this->line('Excluded from import: tasks='.($tasksSkipped).
            ' (of which '.$tasksWithCustomer.' had a customer),'.
            ' customerless-jobs='.$customerlessJobsSkipped.
            ', no-site-jobs='.$noSiteJobsSkipped.
            ', projects-without-export-customer='.$projectsWithoutExportCustomerSkipped.
            ', orphan-locations='.$orphanLocationsSkipped);
        if ($excludedJobs !== []) {
            $this->line('Excluded jobs: '.implode(', ', $excludedJobs));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $source, string $name): array
    {
        $path = $source.'/'.$name;

        if (! is_file($path)) {
            throw new RuntimeException("Missing archive file: {$name}");
        }

        // The export files start with a UTF-8 BOM; strip it before parsing so
        // the first quoted header cell parses as a quoted field.
        $contents = (string) file_get_contents($path);

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException("Unreadable archive file: {$name}");
        }

        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];
        $header = null;

        while (($record = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(static fn (string $value): string => trim($value), $record);

                continue;
            }

            if ($record === [null] || trim(implode('', $record)) === '') {
                continue;
            }

            $entry = [];
            foreach ($header as $index => $column) {
                $entry[$column] = trim((string) ($record[$index] ?? ''));
            }

            $rows[] = $entry;
        }

        fclose($handle);

        return $rows;
    }

    private function composeAddress(string $line1, string $line2, string $city, string $state, string $zip): string
    {
        $parts = [];

        foreach ([$line1, $line2, $city] as $part) {
            $part = trim($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        $tail = trim(trim($state).' '.trim($zip));

        if ($tail !== '') {
            $parts[] = $tail;
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function json(array $values): string
    {
        if ($values === []) {
            return '';
        }

        return (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
