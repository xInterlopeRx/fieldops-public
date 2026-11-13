<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\MicrosoftGraphSharePointOnboardingCanary;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphRuntimeTargetResolver;
use App\Services\MicrosoftGraphSharePointStorageService;
use App\Services\SharePointAttachmentMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SharePointAttachmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_counts_legacy_attachments_without_graph_writes_or_pointer_changes(): void
    {
        [$admin, $target, $attachment] = $this->migrationFixture();
        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage->shouldNotReceive('verifyTarget');
        $storage->shouldNotReceive('copyFileToTarget');

        $inventory = app(SharePointAttachmentMigrationService::class)->inventory();

        $this->assertSame(1, $inventory['pending']);
        $this->assertSame(0, $inventory['already_migrated']);
        $this->assertSame(0, $inventory['unexpected']);
        $this->assertSame(16, $inventory['pending_bytes']);
        $this->assertSame('legacy-drive-id', $attachment->fresh()->sharepoint_drive_id);
        $this->assertNull($target->fresh()->business_attachments_writes_activated_at);
    }

    public function test_migration_copies_one_item_updates_only_storage_pointers_and_auto_enables_business_writes(): void
    {
        [$admin, $target, $attachment] = $this->migrationFixture();
        $attachment->forceFill([
            'filename' => 'customer-note.eml',
            'mime_type' => 'text/plain',
        ])->save();
        $mimeType = 'text/plain';

        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage->shouldReceive('verifyTarget')->twice();
        $storage->shouldNotReceive('delete');
        $destinationName = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://fieldops.bhinservices.com/sharepoint-migration/'.$attachment->getKey(),
        ).'.eml';
        $storage->shouldReceive('copyFileToTarget')
            ->once()
            ->with(
                'legacy-item-id',
                'legacy-drive-id',
                'business-site-id',
                'business-drive-id',
                'FieldOps/customer-'.$attachment->company_id.'/'.$destinationName,
                16,
                $mimeType,
                false,
            )
            ->andReturn([
                'metadata' => [
                    'id' => 'business-item-id',
                    'name' => $destinationName,
                    'size' => 16,
                    'mime_type' => 'message/rfc822',
                    'e_tag' => 'business-etag',
                    'c_tag' => null,
                    'last_modified_at' => null,
                    'sharepoint_url' => 'https://tenant.sharepoint.com/sites/business-attachments/business-item-id',
                ],
                'sha256' => hash('sha256', 'fixture-file-bytes'),
                'destination_sha256' => hash('sha256', 'fixture-file-bytes'),
                'source_size' => 16,
                'source_mime_type' => $mimeType,
                'destination_mime_type' => 'message/rfc822',
                'transformed' => false,
                'reused' => false,
            ]);

        $progress = [];
        $summary = app(SharePointAttachmentMigrationService::class)->migrate(
            $admin,
            onProgress: function (array $item) use (&$progress): void {
                $progress[] = $item;
            },
        );
        $migrated = $attachment->fresh();

        $this->assertSame(1, $summary['migrated'], json_encode($progress));
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['remaining']);
        $this->assertTrue($summary['writes_activated']);
        $this->assertSame($attachment->company_id, $migrated->company_id);
        $this->assertSame($attachment->site_id, $migrated->site_id);
        $this->assertSame($attachment->project_id, $migrated->project_id);
        $this->assertSame($attachment->job_id, $migrated->job_id);
        $this->assertSame(SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS, $migrated->sharepoint_target);
        $this->assertSame('business-site-id', $migrated->sharepoint_site_id);
        $this->assertSame('business-drive-id', $migrated->sharepoint_drive_id);
        $this->assertSame('business-item-id', $migrated->sharepoint_item_id);
        $this->assertSame(16, $migrated->file_size);
        $this->assertSame('https://tenant.sharepoint.com/sites/business-attachments/business-item-id', $migrated->sharepoint_url);
        $this->assertNotNull($target->fresh()->business_attachments_writes_activated_at);
        $pointerAudit = AuditEvent::query()->where('event', 'sharepoint_attachment_target_migrated')->sole();
        $this->assertSame('legacy-item-id', $pointerAudit->metadata['source_pointer']['item_id']);
        $this->assertSame('business-item-id', $pointerAudit->metadata['destination_pointer']['item_id']);
        $this->assertSame('text/plain', $pointerAudit->metadata['source_mime_type']);
        $this->assertSame('message/rfc822', $pointerAudit->metadata['destination_mime_type']);
        $this->assertDatabaseHas('audit_events', ['event' => 'sharepoint_attachments_migrated_to_business_target']);
    }

    public function test_migration_indexes_failures_and_cursor_continues_after_failed_attachment(): void
    {
        [$admin, , $failedAttachment] = $this->migrationFixture();
        $nextAttachment = SharePointAttachment::query()->create([
            'company_id' => $failedAttachment->company_id,
            'site_id' => $failedAttachment->site_id,
            'job_id' => $failedAttachment->job_id,
            'uploaded_by' => $admin->getKey(),
            'filename' => 'next-customer-photo.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 16,
            'sharepoint_drive_id' => 'legacy-drive-id',
            'sharepoint_item_id' => 'legacy-next-item-id',
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/work-schedules/legacy-next-item-id',
        ]);

        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage->shouldReceive('verifyTarget')->times(4);
        $storage->shouldNotReceive('delete');
        $storage->shouldReceive('copyFileToTarget')
            ->once()
            ->with(
                'legacy-item-id',
                'legacy-drive-id',
                'business-site-id',
                'business-drive-id',
                Mockery::type('string'),
                16,
                'application/pdf',
                false,
            )
            ->andThrow(new MicrosoftGraphApiException('graph_sharepoint_source_unavailable', 404));

        $destinationName = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://fieldops.bhinservices.com/sharepoint-migration/'.$nextAttachment->getKey(),
        ).'.pdf';
        $storage->shouldReceive('copyFileToTarget')
            ->once()
            ->with(
                'legacy-next-item-id',
                'legacy-drive-id',
                'business-site-id',
                'business-drive-id',
                'FieldOps/customer-'.$nextAttachment->company_id.'/'.$destinationName,
                16,
                'application/pdf',
                false,
            )
            ->andReturn([
                'metadata' => [
                    'id' => 'business-next-item-id',
                    'name' => $destinationName,
                    'size' => 16,
                    'mime_type' => 'application/pdf',
                    'e_tag' => 'business-next-etag',
                    'c_tag' => null,
                    'last_modified_at' => null,
                    'sharepoint_url' => 'https://tenant.sharepoint.com/sites/business-attachments/business-next-item-id',
                ],
                'sha256' => hash('sha256', 'next-fixture-file-bytes'),
                'destination_sha256' => hash('sha256', 'next-fixture-file-bytes'),
                'source_size' => 16,
                'transformed' => false,
                'reused' => false,
            ]);

        $migration = app(SharePointAttachmentMigrationService::class);
        $failedSummary = $migration->migrate($admin, limit: 1, afterId: 0);
        $failedBatchAudit = AuditEvent::query()
            ->where('event', 'sharepoint_attachments_migrated_to_business_target')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(1, $failedSummary['failed']);
        $this->assertSame($failedAttachment->getKey(), $failedSummary['last_attempted_id']);
        $this->assertSame([
            [
                'attachment_id' => $failedAttachment->getKey(),
                'failure_code' => 'graph_sharepoint_source_unavailable',
            ],
        ], $failedSummary['failed_attachments']);
        $this->assertSame(
            $failedSummary['failed_attachments'][0]['attachment_id'],
            $failedBatchAudit->metadata['failed_attachments'][0]['attachment_id'],
        );
        $this->assertSame(
            $failedSummary['failed_attachments'][0]['failure_code'],
            $failedBatchAudit->metadata['failed_attachments'][0]['failure_code'],
        );
        $this->assertSame(SharePointAttachment::TARGET_WORK_SCHEDULES, $failedAttachment->fresh()->sharepoint_target);

        $continuedSummary = $migration->migrate($admin, limit: 1, afterId: $failedSummary['last_attempted_id']);

        $this->assertSame(1, $continuedSummary['migrated']);
        $this->assertSame(0, $continuedSummary['failed']);
        $this->assertSame($nextAttachment->getKey(), $continuedSummary['last_attempted_id']);
        $this->assertSame(1, $continuedSummary['remaining']);
        $this->assertSame(SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS, $nextAttachment->fresh()->sharepoint_target);
        $this->assertSame(SharePointAttachment::TARGET_WORK_SCHEDULES, $failedAttachment->fresh()->sharepoint_target);
        $continuedBatchAudit = AuditEvent::query()
            ->where('event', 'sharepoint_attachments_migrated_to_business_target')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($failedSummary['last_attempted_id'], $continuedBatchAudit->metadata['after_id']);
        $this->assertSame($nextAttachment->getKey(), $continuedBatchAudit->metadata['last_attempted_id']);
        $this->assertNull(MicrosoftGraphOnboardingTarget::query()->firstOrFail()->business_attachments_writes_activated_at);
    }

    public function test_source_size_reconciliation_updates_only_verified_size_and_audits_original_value(): void
    {
        [$admin, $target, $attachment] = $this->migrationFixture();
        $mimeType = 'application/vnd.ms-outlook';
        $attachment->forceFill([
            'filename' => 'saved-message.msg',
            'mime_type' => $mimeType,
            'file_size' => 188_928,
        ])->save();

        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage->shouldReceive('verifiedSourceForSizeReconciliation')
            ->once()
            ->with('legacy-item-id', 'legacy-drive-id', $mimeType)
            ->andReturn([
                'size' => 202_752,
                'content_size' => 202_752,
                'mime_type' => $mimeType,
                'sha256' => hash('sha256', 'verified-msg-source-bytes'),
            ]);
        $storage->shouldNotReceive('copyFileToTarget');
        $storage->shouldNotReceive('delete');

        $this->artisan('attachments:migrate-sharepoint-to-business-drive', [
            '--execute' => true,
            '--reconcile-source-size-id' => $attachment->getKey(),
            '--actor-email' => $admin->email,
        ])->assertExitCode(0);

        $this->assertSame(202_752, $attachment->fresh()->file_size);
        $this->assertSame(SharePointAttachment::TARGET_WORK_SCHEDULES, $attachment->fresh()->sharepoint_target);
        $this->assertSame('legacy-item-id', $attachment->fresh()->sharepoint_item_id);
        $this->assertNull($target->fresh()->business_attachments_writes_activated_at);

        $audit = AuditEvent::query()->where('event', 'sharepoint_attachment_source_size_reconciled')->sole();
        $this->assertSame(188_928, $audit->metadata['correction']['previous_value']);
        $this->assertSame(202_752, $audit->metadata['correction']['new_value']);
        $this->assertSame(hash('sha256', 'verified-msg-source-bytes'), $audit->metadata['verified_source']['sha256']);
        $this->assertDatabaseMissing('audit_events', ['event' => 'sharepoint_attachments_migrated_to_business_target']);
    }

    public function test_explicit_approval_adopts_only_the_verified_existing_nonidentical_destination_and_audits_both_hashes(): void
    {
        [$admin, $target, $attachment] = $this->migrationFixture();
        $sourceBytes = str_repeat('s', 202_752);
        $destinationBytes = str_repeat('d', 202_752);
        $mimeType = 'application/vnd.ms-outlook';
        $attachment->forceFill([
            'filename' => 'saved-message.msg',
            'mime_type' => $mimeType,
            'file_size' => strlen($sourceBytes),
        ])->save();

        $destinationName = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://fieldops.bhinservices.com/sharepoint-migration/'.$attachment->getKey(),
        ).'.msg';
        $destinationPath = 'FieldOps/customer-'.$attachment->company_id.'/'.$destinationName;
        $sourceMetadata = [
            'id' => 'legacy-item-id',
            'name' => 'saved-message.msg',
            'size' => strlen($sourceBytes),
            'mime_type' => $mimeType,
            'e_tag' => 'source-etag',
            'c_tag' => null,
            'last_modified_at' => null,
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/work-schedules/saved-message.msg',
        ];
        $destinationMetadata = [
            'id' => 'business-item-id',
            'name' => 'copy.msg',
            'size' => strlen($destinationBytes),
            'mime_type' => $mimeType,
            'e_tag' => 'destination-etag',
            'c_tag' => null,
            'last_modified_at' => null,
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/business-attachments/copy.msg',
        ];

        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage->shouldReceive('verifyTarget')->twice();
        $storage->shouldReceive('downloadWithMetadata')
            ->twice()
            ->withArgs(fn (string $itemId, string $driveId): bool => in_array(
                [$itemId, $driveId],
                [
                    ['legacy-item-id', 'legacy-drive-id'],
                    ['business-item-id', 'business-drive-id'],
                ],
                true,
            ))
            ->andReturn(
                ['metadata' => $sourceMetadata, 'contents' => $sourceBytes],
                ['metadata' => $destinationMetadata, 'contents' => $destinationBytes],
            );
        $storage->shouldReceive('metadataByPath')
            ->once()
            ->with('business-drive-id', $destinationPath)
            ->andReturn($destinationMetadata);
        $storage->shouldNotReceive('copyFileToTarget');
        $storage->shouldNotReceive('uploadToTarget');
        $storage->shouldNotReceive('delete');

        $this->artisan('attachments:migrate-sharepoint-to-business-drive', [
            '--execute' => true,
            '--accept-existing-nonidentical-destination-id' => $attachment->getKey(),
            '--actor-email' => $admin->email,
        ])->assertExitCode(0);

        $migrated = $attachment->fresh();
        $this->assertSame(SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS, $migrated->sharepoint_target);
        $this->assertSame('business-site-id', $migrated->sharepoint_site_id);
        $this->assertSame('business-drive-id', $migrated->sharepoint_drive_id);
        $this->assertSame('business-item-id', $migrated->sharepoint_item_id);
        $this->assertSame(strlen($sourceBytes), $migrated->file_size);
        $this->assertNull($target->fresh()->business_attachments_writes_activated_at);

        $acceptanceAudit = AuditEvent::query()
            ->where('event', 'sharepoint_attachment_nonidentical_destination_accepted')
            ->sole();
        $this->assertSame($admin->getKey(), $acceptanceAudit->actor_user_id);
        $this->assertSame(hash('sha256', $sourceBytes), $acceptanceAudit->metadata['source_sha256']);
        $this->assertSame(hash('sha256', $destinationBytes), $acceptanceAudit->metadata['destination_sha256']);
        $this->assertFalse($acceptanceAudit->metadata['source_destination_byte_identical']);
        $this->assertTrue($acceptanceAudit->metadata['source_retained']);

        $pointerAudit = AuditEvent::query()->where('event', 'sharepoint_attachment_target_migrated')->sole();
        $this->assertTrue($pointerAudit->metadata['operator_accepted_nonidentical_destination']);
        $this->assertSame('legacy-item-id', $pointerAudit->metadata['source_pointer']['item_id']);
        $this->assertSame('business-item-id', $pointerAudit->metadata['destination_pointer']['item_id']);
    }

    public static function invalidApprovedDestinationCases(): array
    {
        return [
            'size mismatch' => ['application/vnd.ms-outlook', 15, str_repeat('d', 15), 'graph_sharepoint_attachment_approved_destination_verification_failed'],
            'MIME mismatch' => ['application/octet-stream', 16, str_repeat('d', 16), 'graph_sharepoint_attachment_approved_destination_verification_failed'],
            'already byte-identical' => ['application/vnd.ms-outlook', 16, str_repeat('s', 16), 'graph_sharepoint_attachment_destination_is_byte_identical'],
        ];
    }

    #[DataProvider('invalidApprovedDestinationCases')]
    public function test_explicit_approval_refuses_destination_with_invalid_size_mime_or_no_byte_difference(
        string $destinationMimeType,
        int $destinationSize,
        string $destinationBytes,
        string $expectedFailureCode,
    ): void {
        [$admin, $target, $attachment] = $this->migrationFixture();
        $sourceBytes = str_repeat('s', 16);
        $mimeType = 'application/vnd.ms-outlook';
        $attachment->forceFill([
            'filename' => 'saved-message.msg',
            'mime_type' => $mimeType,
            'file_size' => strlen($sourceBytes),
        ])->save();

        $destinationName = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://fieldops.bhinservices.com/sharepoint-migration/'.$attachment->getKey(),
        ).'.msg';
        $destinationPath = 'FieldOps/customer-'.$attachment->company_id.'/'.$destinationName;
        $sourceMetadata = [
            'id' => 'legacy-item-id',
            'name' => 'saved-message.msg',
            'size' => strlen($sourceBytes),
            'mime_type' => $mimeType,
            'e_tag' => 'source-etag',
            'c_tag' => null,
            'last_modified_at' => null,
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/work-schedules/saved-message.msg',
        ];
        $destinationMetadata = [
            'id' => 'business-item-id',
            'name' => 'copy.msg',
            'size' => $destinationSize,
            'mime_type' => $destinationMimeType,
            'e_tag' => 'destination-etag',
            'c_tag' => null,
            'last_modified_at' => null,
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/business-attachments/copy.msg',
        ];

        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage->shouldReceive('verifyTarget')->twice();
        $storage->shouldReceive('downloadWithMetadata')
            ->twice()
            ->andReturn(
                ['metadata' => $sourceMetadata, 'contents' => $sourceBytes],
                ['metadata' => $destinationMetadata, 'contents' => $destinationBytes],
            );
        $storage->shouldReceive('metadataByPath')
            ->once()
            ->with('business-drive-id', $destinationPath)
            ->andReturn($destinationMetadata);
        $storage->shouldNotReceive('copyFileToTarget');
        $storage->shouldNotReceive('uploadToTarget');
        $storage->shouldNotReceive('delete');

        $output = new BufferedOutput;
        $exitCode = Artisan::call('attachments:migrate-sharepoint-to-business-drive', [
            '--execute' => true,
            '--accept-existing-nonidentical-destination-id' => $attachment->getKey(),
            '--actor-email' => $admin->email,
        ], $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($expectedFailureCode, $output->fetch());
        $this->assertSame(SharePointAttachment::TARGET_WORK_SCHEDULES, $attachment->fresh()->sharepoint_target);
        $this->assertNull($target->fresh()->business_attachments_writes_activated_at);
        $this->assertDatabaseMissing('audit_events', ['event' => 'sharepoint_attachment_nonidentical_destination_accepted']);
        $this->assertDatabaseMissing('audit_events', ['event' => 'sharepoint_attachment_target_migrated']);
    }

    public function test_migration_accepts_verified_size_variance_for_sharepoint_transformed_office_files(): void
    {
        Sleep::fake();
        [$admin, $target, $attachment] = $this->migrationFixture();
        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $attachment->forceFill([
            'filename' => 'customer-data.xlsx',
            'mime_type' => $mimeType,
            'file_size' => 13_060,
        ])->save();

        $storage = $this->mock(MicrosoftGraphSharePointStorageService::class);
        $storage->shouldReceive('verifyTarget')->twice();
        $storage->shouldNotReceive('delete');
        $destinationName = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://fieldops.bhinservices.com/sharepoint-migration/'.$attachment->getKey(),
        ).'.xlsx';
        $copyResult = [
            'metadata' => [
                'id' => 'business-xlsx-item-id',
                'name' => $destinationName,
                'size' => 21_515,
                'mime_type' => $mimeType,
                'e_tag' => 'business-xlsx-etag',
                'c_tag' => null,
                'last_modified_at' => null,
                'sharepoint_url' => 'https://tenant.sharepoint.com/sites/business-attachments/'.$destinationName,
            ],
            'sha256' => hash('sha256', 'source-xlsx-bytes'),
            'destination_sha256' => hash('sha256', 'transformed-xlsx-bytes'),
            'source_size' => 22_529,
            'transformed' => true,
            'reused' => true,
        ];
        $copyAttempt = 0;
        $storage->shouldReceive('copyFileToTarget')
            ->twice()
            ->with(
                'legacy-item-id',
                'legacy-drive-id',
                'business-site-id',
                'business-drive-id',
                'FieldOps/customer-'.$attachment->company_id.'/'.$destinationName,
                13_060,
                $mimeType,
                true,
            )
            ->andReturnUsing(function () use (&$copyAttempt, $copyResult): array {
                $copyAttempt++;

                if ($copyAttempt === 1) {
                    throw new MicrosoftGraphApiException('graph_sharepoint_storage_size_mismatch', 502);
                }

                return $copyResult;
            });

        $progress = [];
        $summary = app(SharePointAttachmentMigrationService::class)->migrate(
            $admin,
            onProgress: function (array $item) use (&$progress): void {
                $progress[] = $item;
            },
        );

        $this->assertSame(1, $summary['migrated'], json_encode($progress));
        $this->assertSame(1, $summary['reused']);
        $this->assertSame(1, $summary['office_transformed']);
        $this->assertSame(0, $summary['failed']);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds == 5);
        $this->assertTrue($summary['writes_activated']);
        $this->assertSame(21_515, $attachment->fresh()->file_size);
        $this->assertSame('business-xlsx-item-id', $attachment->fresh()->sharepoint_item_id);
        $this->assertNotNull($target->fresh()->business_attachments_writes_activated_at);
        $pointerAudit = AuditEvent::query()->where('event', 'sharepoint_attachment_target_migrated')->sole();
        $this->assertTrue($pointerAudit->metadata['office_transformed_by_sharepoint']);
        $this->assertNotSame($pointerAudit->metadata['source_sha256'], $pointerAudit->metadata['destination_sha256']);
    }

    /** @return array{User, MicrosoftGraphOnboardingTarget, SharePointAttachment} */
    private function migrationFixture(): array
    {
        config([
            'services.microsoft_graph.work_schedules.site_id' => 'legacy-site-id',
            'services.microsoft_graph.work_schedules.drive_id' => 'legacy-drive-id',
        ]);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $target = MicrosoftGraphOnboardingTarget::query()->create([
            'environment' => app()->environment(),
            'tenant_id' => '11111111-1111-4111-8111-111111111111',
            'client_id' => '22222222-2222-4222-8222-222222222222',
            'notification_mailbox_id' => '33333333-3333-4333-8333-333333333333',
            'notification_mailbox_address' => 'fieldops@example.test',
            'target_is_dedicated_shared_mailbox' => true,
            'work_schedules_group_id' => '44444444-4444-4444-8444-444444444444',
            'work_schedules_site_id' => 'legacy-site-id',
            'work_schedules_drive_id' => 'legacy-drive-id',
            'business_attachments_site_id' => 'business-site-id',
            'business_attachments_drive_id' => 'business-drive-id',
            'non_target_mailbox_email' => 'control@example.test',
            'non_target_is_user_mailbox' => true,
            'non_target_site_id' => 'control-site-id',
            'non_target_drive_id' => 'control-drive-id',
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'target_contacts_status' => 'accessible',
            'non_target_contacts_status' => 'denied',
            'boundary_verified_at' => now(),
            'contacts_boundary_verified_at' => now(),
            'runtime_activated_at' => now(),
        ]);
        $this->instance(MicrosoftGraphRuntimeTargetResolver::class, new class($target) extends MicrosoftGraphRuntimeTargetResolver
        {
            public function __construct(private readonly MicrosoftGraphOnboardingTarget $target) {}

            public function activeTarget(): ?MicrosoftGraphOnboardingTarget
            {
                return $this->target;
            }

            public function requiresManagedTarget(): bool
            {
                return true;
            }
        });
        MicrosoftGraphSharePointOnboardingCanary::query()->create([
            'id' => (string) Str::uuid(),
            'target_id' => $target->getKey(),
            'actor_user_id' => $admin->getKey(),
            'environment' => $target->environment,
            'tenant_id' => $target->tenant_id,
            'client_id' => $target->client_id,
            'site_id' => $target->business_attachments_site_id,
            'drive_id' => $target->business_attachments_drive_id,
            'status' => MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANED,
            'operation_id' => (string) Str::uuid(),
            'folder_name' => 'FieldOps setup canary',
            'remote_item_id' => 'canary-item-id',
            'remote_etag' => 'canary-etag',
            'attempted_at' => now()->subMinute(),
            'created_remotely_at' => now()->subMinute(),
            'cleaned_at' => now(),
        ]);
        $customer = Customer::query()->create(['name' => 'Migration customer']);
        $site = Site::query()->create(['customer_id' => $customer->getKey(), 'name' => 'Migration site']);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'title' => 'Migration job',
            'status' => 'created',
        ]);
        $attachment = SharePointAttachment::query()->create([
            'company_id' => $customer->getKey(),
            'site_id' => $site->getKey(),
            'job_id' => $job->getKey(),
            'uploaded_by' => $admin->getKey(),
            'filename' => 'customer-photo.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 16,
            'sharepoint_drive_id' => 'legacy-drive-id',
            'sharepoint_item_id' => 'legacy-item-id',
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/work-schedules/legacy-item-id',
        ]);

        return [$admin, $target, $attachment];
    }
}
