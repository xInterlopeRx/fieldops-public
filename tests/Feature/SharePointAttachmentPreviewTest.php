<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Customer;
use App\Models\SharePointAttachment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AttachmentListService;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharePointAttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_thumbnail_and_preview_are_reencoded_to_bounded_jpegs(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $attachment = $this->attachment($admin, [
            'filename' => 'equipment.png',
            'mime_type' => 'image/png',
        ]);
        $contents = $this->pngImage(640, 320);
        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage
            ->shouldReceive('thumbnail')
            ->once()
            ->with($attachment->sharepoint_item_id)
            ->andReturn(['contents' => $contents, 'width' => 640, 'height' => 320]);
        $storage
            ->shouldReceive('downloadWithMetadata')
            ->once()
            ->with($attachment->sharepoint_item_id)
            ->andReturn($this->download($attachment, $contents, 'image/png'));

        $thumbnail = $this->actingAs($admin)
            ->get(route('attachments.thumbnail', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');

        $thumbnailInfo = getimagesizefromstring($thumbnail->getContent());
        $this->assertIsArray($thumbnailInfo);
        $this->assertSame(320, $thumbnailInfo[0]);
        $this->assertSame(160, $thumbnailInfo[1]);

        $preview = $this->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $previewInfo = getimagesizefromstring($preview->getContent());
        $this->assertIsArray($previewInfo);
        $this->assertSame(640, $previewInfo[0]);
        $this->assertSame(320, $previewInfo[1]);
    }

    public function test_pdf_and_text_documents_use_safe_inline_browser_previews(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $pdfAttachment = $this->attachment($admin, [
            'filename' => 'service-report.pdf',
            'mime_type' => 'application/pdf',
        ]);
        $textAttachment = $this->attachment($admin, [
            'filename' => 'service-notes.txt',
            'mime_type' => 'text/plain',
            'sharepoint_drive_id' => 'drive-preview-text',
            'sharepoint_item_id' => 'item-preview-text',
        ]);
        $csvAttachment = $this->attachment($admin, [
            'filename' => 'measurements.csv',
            'mime_type' => 'text/csv',
            'sharepoint_drive_id' => 'drive-preview-csv',
            'sharepoint_item_id' => 'item-preview-csv',
        ]);
        $notes = "<script>alert('not executed')</script>\nInspection complete.";
        $csv = "reading,unit\n42,psi\n";
        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage
            ->shouldReceive('downloadWithMetadata')
            ->once()
            ->with('item-1')
            ->andReturn($this->download($pdfAttachment, $pdf, 'application/pdf'));
        $storage
            ->shouldReceive('downloadWithMetadata')
            ->once()
            ->with('item-preview-text')
            ->andReturn($this->download($textAttachment, $notes, 'text/plain'));
        $storage
            ->shouldReceive('downloadWithMetadata')
            ->once()
            ->with('item-preview-csv')
            ->andReturn($this->download($csvAttachment, $csv, 'text/csv'));

        $this->actingAs($admin)
            ->get(route('attachments.preview', $pdfAttachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee($pdf, escape: false);

        $this->get(route('attachments.preview', $textAttachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee($notes, escape: false);

        $this->get(route('attachments.preview', $csvAttachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee($csv, escape: false);
    }

    public function test_office_document_preview_is_rejected_without_fetching_remote_content(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $attachment = $this->attachment($admin, [
            'filename' => 'service-plan.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldNotReceive('downloadWithMetadata');

        $this->actingAs($admin)
            ->get(route('attachments.preview', $attachment))
            ->assertStatus(415)
            ->assertSee('Attachment preview is unavailable.')
            ->assertDontSee('graph_sharepoint');

        $resources = app(AttachmentListService::class)->forCustomerWorkspace($attachment->company);
        $this->assertNull($resources[0]['previewUrl']);
        $this->assertNull($resources[0]['thumbnailUrl']);
    }

    public function test_invalid_raster_content_is_not_served_as_an_image(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $attachment = $this->attachment($admin, [
            'filename' => 'spoofed.png',
            'mime_type' => 'image/png',
        ]);
        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('thumbnail')
            ->once()
            ->with($attachment->sharepoint_item_id)
            ->andReturn([
                'contents' => '%PDF-1.4 not a png',
                'width' => 320,
                'height' => 200,
            ]);

        $this->actingAs($admin)
            ->get(route('attachments.thumbnail', $attachment))
            ->assertStatus(415)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDontSee('%PDF-1.4 not a png');
    }

    public function test_graph_preview_failures_return_a_generic_message_without_provider_details(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $attachment = $this->attachment($admin, [
            'filename' => 'service-report.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('downloadWithMetadata')
            ->once()
            ->with($attachment->sharepoint_item_id)
            ->andThrow(new MicrosoftGraphApiException('graph_preview_secret_marker', 502));

        $this->actingAs($admin)
            ->get(route('attachments.preview', $attachment))
            ->assertStatus(502)
            ->assertSee('Attachment preview is unavailable.')
            ->assertDontSee('graph_preview_secret_marker');
    }

    public function test_unassigned_basic_user_cannot_fetch_a_preview(): void
    {
        $company = Customer::query()->create(['name' => 'Preview access customer']);
        $site = $company->sites()->create(['name' => 'Preview access site']);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'job_number' => 'J-PREVIEW-1',
            'title' => 'Preview access job',
            'status' => 'pending',
        ]);
        $attachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'site_id' => $site->getKey(),
            'job_id' => $job->getKey(),
        ]));
        $unassigned = User::factory()->create(['status' => 'active']);

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldNotReceive('downloadWithMetadata');

        $this->actingAs($unassigned)
            ->get(route('attachments.preview', $attachment))
            ->assertForbidden();
    }

    public function test_unauthenticated_user_is_redirected_before_preview_content_is_requested(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $attachment = $this->attachment($admin, [
            'filename' => 'service-report.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldNotReceive('downloadWithMetadata');

        $this->get(route('attachments.preview', $attachment))
            ->assertRedirect(route('login'));
    }

    private function attachment(User $admin, array $overrides = []): SharePointAttachment
    {
        $company = Customer::query()->create(['name' => 'Preview customer']);

        return SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'uploaded_by' => $admin->getKey(),
            ...$overrides,
        ]));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function metadataAttributes(array $overrides = []): array
    {
        return array_replace([
            'company_id' => null,
            'site_id' => null,
            'project_id' => null,
            'job_id' => null,
            'uploaded_by' => null,
            'filename' => 'preview-file.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'sharepoint_drive_id' => 'drive-preview',
            'sharepoint_item_id' => 'item-1',
            'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/item-1',
        ], $overrides);
    }

    /** @return array{metadata: array{id: string, name: string, size: int, mime_type: string, sharepoint_url: string}, contents: string} */
    private function download(SharePointAttachment $attachment, string $contents, string $mimeType): array
    {
        return [
            'metadata' => [
                'id' => $attachment->sharepoint_item_id,
                'name' => $attachment->filename,
                'size' => strlen($contents),
                'mime_type' => $mimeType,
                'sharepoint_url' => $attachment->sharepoint_url,
            ],
            'contents' => $contents,
        ];
    }

    private function mockDownload(
        SharePointAttachment $attachment,
        string $contents,
        string $mimeType,
        int $times = 1,
    ): void {
        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('downloadWithMetadata')
            ->times($times)
            ->with($attachment->sharepoint_item_id)
            ->andReturn($this->download($attachment, $contents, $mimeType));
    }

    private function pngImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 25, 90, 150);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return is_string($contents) ? $contents : '';
    }
}
