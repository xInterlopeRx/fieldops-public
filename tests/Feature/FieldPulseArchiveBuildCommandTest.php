<?php

namespace Tests\Feature;

use App\Services\FieldPulse\FieldPulseDocument;
use Tests\TestCase;

class FieldPulseArchiveBuildCommandTest extends TestCase
{
    public function test_build_document_from_archive_produces_the_uploadable_workbook(): void
    {
        $source = $this->fixtureArchive();
        $output = tempnam(sys_get_temp_dir(), 'fp-archive').'.xlsx';

        $this->artisan('fieldpulse:build-document', ['source' => $source, 'output' => $output])
            ->expectsOutputToContain('Project links: matched=1 unmatched=0 customer-mismatches=0')
            ->assertExitCode(0);

        $data = app(FieldPulseDocument::class)->read($output);

        // Customers: composed address and archived extras in metadata.
        $this->assertCount(2, $data['Customers']);
        $alpha = $data['Customers'][0];
        $this->assertSame('9001', $alpha['fieldpulse_id']);
        $this->assertSame('1 Alpha Way, Austin, TX 78701', $alpha['address']);
        $this->assertSame('Alpha note', $alpha['notes']);
        $this->assertStringContainsString('billing_address', (string) $alpha['metadata_json']);

        // Locations: deterministic synthesized ids, main-location flag, notes in metadata.
        $this->assertCount(3, $data['Locations']);
        $this->assertSame('CSV-LOC-9001-001', $data['Locations'][0]['fieldpulse_id']);
        $this->assertSame('Main Location', $data['Locations'][0]['title']);
        $this->assertSame('true', $data['Locations'][0]['is_main_location']);
        $this->assertSame('CSV-LOC-9001-002', $data['Locations'][1]['fieldpulse_id']);
        $this->assertSame('Gate code 1234', $this->meta($data['Locations'][0])['notes']);
        $this->assertSame('CSV-LOC-9002-001', $data['Locations'][2]['fieldpulse_id']);

        // Projects retain their FieldPulse identity and parent Customer so Jobs
        // can be linked relationally during the workbook import.
        $this->assertCount(1, $data['Projects']);
        $this->assertSame('5001', $data['Projects'][0]['fieldpulse_id']);
        $this->assertSame('9002', $data['Projects'][0]['customer_fieldpulse_id']);
        $this->assertSame('5001', $data['Projects'][0]['project_number']);
        $this->assertSame('Cabling', $this->meta($data['Projects'][0])['description']);

        // Jobs: statuses normalized, customer resolved through the project,
        // location matched by name, archive times packed into metadata.
        // FieldPulse Tasks are excluded entirely.
        $this->assertCount(3, $data['Jobs']);
        [$job7001, $job7002, $job7003] = $data['Jobs'];
        $this->assertNotContains('7004', array_column($data['Jobs'], 'fieldpulse_id'));
        $this->assertSame('created', $job7001['status']);
        $this->assertSame('9001', $job7001['customer_fieldpulse_id']);
        $this->assertSame('CSV-LOC-9001-001', $job7001['location_fieldpulse_id']);
        $this->assertSame('2025-01-15 08:00', $this->meta($job7001)['start_at']);
        $this->assertSame('completed', $job7002['status']);
        $this->assertSame('9002', $job7002['customer_fieldpulse_id']);
        $this->assertSame('project', $this->meta($job7002)['customer_source']);
        $this->assertSame('5001', $this->meta($job7002)['project_no']);
        $this->assertSame('2025-02-20 16:00', $this->meta($job7002)['completed_at']);
        $this->assertSame('in_progress', $job7003['status']);

        // Notes: job notes, customer notes, and job/project comments; the
        // invoice-domain comment is skipped and stays in the archive.
        $sources = array_column($data['Notes'], 'source_record_id');
        $this->assertContains('job_note:7001', $sources);
        $this->assertContains('customer_note:9001', $sources);
        $this->assertContains('customer_note:9002', $sources);
        $this->assertContains('comment:7001', $sources);
        $this->assertContains('comment:project:5001', $sources);
        $this->assertNotContains('comment:invoice:9001', $sources);
        $comment = $data['Notes'][array_search('comment:7001', $sources, true)];
        $this->assertSame('comment', $comment['kind']);
        $this->assertSame('Caleb Kirk', $this->meta($comment)['author']);

        // Contacts: first row per customer is the secondary slot; further rows
        // get deterministic additional types.
        $this->assertCount(3, $data['Contacts']);
        [$first, $second, $third] = $data['Contacts'];
        $this->assertSame('CSV-CON-9001-01', $first['fieldpulse_contact_id']);
        $this->assertSame('secondary', $first['contact_type']);
        $this->assertSame('additional_2', $second['contact_type']);
        $this->assertSame('9002', $third['customer_fieldpulse_id']);
        $this->assertSame('Project Manager', $first['notes']);
    }

    /**
     * Decode the metadata_json cell of a parsed document row.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function meta(array $row): array
    {
        return json_decode($row['metadata_json'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    }

    private function fixtureArchive(): string
    {
        $dir = sys_get_temp_dir().'/fp-archive-fixture-'.uniqid();
        mkdir($dir, 0777, true);

        $bom = "\xEF\xBB\xBF";

        file_put_contents($dir.'/customers.csv', $bom.'"Customer #","Display Name","Company Name","First Name","Last Name","Account Type",Email,Phone,"Mobile Phone","Alt Email","Alt Phone","Address 1","Address 2",City,State,Zip,"Billing Address 1","Billing Address 2","Billing City","Billing State","Billing Zip",Website,"Parent Customer #",Notes,Created'."\n".
            '9001,"Alpha Site Co",,Abe,Alpha,company,alpha@example.test,555-1000,,,alpha-alt@example.test,"1 Alpha Way",,Austin,TX,78701,"9 Billing Rd",,Austin,TX,78702,,"","Alpha note","2025-01-02 08:00"'."\n".
            '9002,"Beta Co","Beta Co Inc",Betty,Beta,company,beta@example.test,555-2000,,,,,,Dallas,TX,75201,,,,,,,,"Beta note","2025-02-03 09:00"'."\n");

        file_put_contents($dir.'/locations.csv', '"Customer #","Location Name","Address 1","Address 2",City,State,Zip,"Main Location",Notes'."\n".
            '9001,"Main Location","1 Alpha Way",,Austin,TX,78701,Yes,"Gate code 1234"'."\n".
            '9001,"Warehouse","2 Beta Blvd",,Austin,TX,78701,No,'."\n".
            '9002,"Beta Office","3 Beta Cir",,Dallas,TX,75201,Yes,'."\n");

        file_put_contents($dir.'/customer_contacts.csv', '"Customer #","First Name","Last Name",Email,Phone,Notes,Created'."\n".
            '9001,Riley,McCabe,riley@example.test,555-0001,"Project Manager","2025-01-09 17:56"'."\n".
            '9001,Sam,Smith,sam@example.test,555-0002,"Supervisor","2025-01-10 10:00"'."\n".
            '9002,Kate,Jones,kate@example.test,555-0003,,"2025-02-05 11:00"'."\n");

        file_put_contents($dir.'/projects.csv', '"Project #","Customer #",Name,Description,Status,Start,End,Notes,Created'."\n".
            '5001,9002,"Beta Project","Cabling",Completed,"2025-02-10 08:00","2025-02-19 17:00","","2025-02-09 09:00"'."\n");

        file_put_contents($dir.'/jobs.csv', '"Job #",Type,"Customer #","Project #","Recurring Job #","Job Type",Subtitle,Status,Start,End,Completed,Location,Notes,Created'."\n".
            '7001,Job,9001,,,"Camera Install","Alpha Site Co",New,"2025-01-15 08:00","2025-01-15 17:00",,Main Location,"Installed 4 cams","2025-01-09 08:00"'."\n".
            '7002,Job,,5001,,"Cabling","Beta Co",Completed,"2025-02-14 08:00","2025-02-14 17:00","2025-02-20 16:00",,,"2025-02-11 09:00"'."\n".
            '7003,Job,9002,,,"Repair","Beta Co","In Progress",,,,,,"2025-02-12 09:00"'."\n".
            '7004,Task,9002,,,,"Admin reminder",,,,,,,"2025-02-13 09:00"'."\n");

        file_put_contents($dir.'/recurring_jobs.csv', '"Recurring Job #",Type,"Customer #","Job Type",Subtitle,"Repeat Rule","Duration (minutes)",Location,Notes,Created'."\n");

        file_put_contents($dir.'/comments.csv', '"On","Record #",Author,Comment,Created'."\n".
            'Job,7001,Caleb Kirk,"Nice work","2025-01-16 09:00"'."\n".
            'Project,5001,Caleb Kirk,"Project wrap","2025-02-21 10:00"'."\n".
            'Invoice,9001,Caleb Kirk,"Invoice comment","2025-03-01 09:00"'."\n");

        return $dir;
    }
}
