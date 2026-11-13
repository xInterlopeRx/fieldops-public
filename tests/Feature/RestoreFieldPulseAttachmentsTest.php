<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Contracts\SharePointAttachmentUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakeSharePointUploader implements SharePointAttachmentUploader
{
    public int $sequence = 0;

    public function uploadForCustomer(UploadedFile $file, Customer $customer, User $uploadedBy): SharePointAttachment
    {
        return $this->store($customer, customerId: (int) $customer->getKey());
    }

    public function uploadForProject(UploadedFile $file, $project, User $uploadedBy): SharePointAttachment
    {
        return $this->store($project, customerId: (int) $project->customer_id);
    }

    public function uploadForWorkOrder(UploadedFile $file, $workOrder, User $uploadedBy, ?string $clientUploadId = null): SharePointAttachment
    {
        return $this->store($workOrder, customerId: (int) $workOrder->site->customer_id, jobId: (int) $workOrder->getKey(), clientUploadId: $clientUploadId);
    }

    private function store(object $owner, int $customerId, ?int $jobId = null, ?string $clientUploadId = null): SharePointAttachment
    {
        $this->sequence++;

        return SharePointAttachment::create([
            'company_id' => $customerId,
            'job_id' => $jobId,
            'filename' => 'fake-'.$this->sequence,
            'mime_type' => 'application/octet-stream',
            'file_size' => 4,
            'sharepoint_drive_id' => 'drive-1',
            'sharepoint_item_id' => 'item-'.$this->sequence,
            'sharepoint_url' => 'https://contoso.sharepoint.com/item-'.$this->sequence,
            'uploaded_by' => 1,
            'client_upload_id' => $clientUploadId,
        ]);
    }
}

class RestoreFieldPulseAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_restores_manifest_files_to_imported_entities_with_exclusions_and_dedupe(): void
    {
        config([
            'services.microsoft_graph.sharepoint_enabled' => true,
            'services.microsoft_graph.work_schedules.drive_id' => 'drive-1',
        ]);
        $this->instance(SharePointAttachmentUploader::class, new FakeSharePointUploader);
        $admin = User::factory()->admin()->create(['status' => 'active']);

        $customer = Customer::create(['client_id' => 1, 'name' => 'Kent Co', 'fieldpulse_id' => '1087']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Main', 'fieldpulse_id' => 'LOC-1087']);
        $workOrder = WorkOrder::create(['site_id' => $site->getKey(), 'title' => 'QNAP Swap', 'status' => 'created', 'fieldpulse_id' => '1002']);

        $archive = $this->fixtureArchive([
            ['row' => 10, 'attached_to' => 'Job', 'customer_no' => '1087', 'job_no' => '1002', 'file_name' => 'site photo', 'file_type' => '.jpg', 'sha256' => 'hash-a', 'local_path' => 'files/by-job/1002/site photo.jpg'],
            ['row' => 11, 'attached_to' => 'Job', 'customer_no' => '1087', 'job_no' => '1002', 'file_name' => 'same content again', 'file_type' => '.jpg', 'sha256' => 'hash-a', 'local_path' => 'files/by-job/1002/same content again.jpg'],
            ['row' => 12, 'attached_to' => 'Project', 'customer_no' => '1087', 'job_no' => '', 'project_no' => '5001', 'file_name' => 'plan', 'file_type' => '.pdf', 'sha256' => 'hash-b', 'local_path' => 'files/by-project/5001/plan.pdf'],
            ['row' => 13, 'attached_to' => 'Customer', 'customer_no' => '1087', 'file_name' => 'logo', 'file_type' => '.png', 'sha256' => 'hash-c', 'local_path' => 'files/by-customer/1087/logo.png'],
            ['row' => 14, 'attached_to' => 'Invoice', 'customer_no' => '1087', 'job_no' => '1002', 'file_name' => 'invoice', 'file_type' => '.pdf', 'sha256' => 'hash-d', 'local_path' => 'files/excluded-invoices/9001/invoice.pdf'],
            ['row' => 15, 'attached_to' => 'Job', 'customer_no' => '', 'job_no' => '9999', 'file_name' => 'orphan', 'file_type' => '.pdf', 'sha256' => 'hash-e', 'local_path' => 'files/by-job/9999/orphan.pdf'],
            ['row' => 16, 'attached_to' => 'Job', 'customer_no' => '1087', 'job_no' => '1002', 'file_name' => 'missing', 'file_type' => '.pdf', 'sha256' => 'hash-f', 'local_path' => 'files/by-job/1002/missing.pdf'],
        ]);

        $this->artisan('attachments:restore-fieldpulse', ['archiveDir' => $archive, '--actor-email' => $admin->email])
            ->assertExitCode(0);

        // Three unique contents uploaded; row 11 is a content duplicate;
        // row 14 excluded; row 15 has no imported target; row 16 failed
        // (staged file missing).
        $this->assertSame(3, SharePointAttachment::count());

        $woAttachment = SharePointAttachment::query()->where('job_id', $workOrder->getKey())->first();
        $this->assertNotNull($woAttachment);
        $this->assertTrue(Str::isUuid($woAttachment->client_upload_id));

        // Results: per-row outcomes recorded.
        $results = file($archive.'/restore-results.jsonl');
        $this->assertCount(7, $results);
        $statuses = array_map(static fn (string $line): string => (json_decode($line, true))['status'], $results);
        $this->assertSame(['uploaded', 'duplicate', 'uploaded', 'uploaded', 'excluded', 'no_target', 'failed'], $statuses);

        // Idempotent: a re-run uploads nothing new.
        $this->artisan('attachments:restore-fieldpulse', ['archiveDir' => $archive, '--actor-email' => $admin->email])
            ->assertExitCode(0);
        $this->assertSame(3, SharePointAttachment::count());
    }

    public function test_refuses_to_run_when_sharepoint_writes_are_disabled(): void
    {
        config(['services.microsoft_graph.sharepoint_enabled' => false]);

        $archive = $this->fixtureArchive([]);

        $this->artisan('attachments:restore-fieldpulse', ['archiveDir' => $archive])
            ->expectsOutputToContain('Refusing to run')
            ->assertExitCode(1);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fixtureArchive(array $rows): string
    {
        $dir = sys_get_temp_dir().'/fp-restore-'.uniqid();
        mkdir($dir.'/files', 0777, true);

        $manifest = [];

        foreach ($rows as $row) {
            $local = $dir.'/'.$row['local_path'];
            @mkdir(dirname($local), 0777, true);

            $contents = 'contents of '.$row['sha256'];

            if ($row['file_name'] !== 'missing') {
                file_put_contents($local, $contents);
            }

            $manifest[] = json_encode($row, JSON_THROW_ON_ERROR);
        }

        file_put_contents($dir.'/manifest.jsonl', implode(PHP_EOL, $manifest).PHP_EOL);

        return $dir;
    }
}
