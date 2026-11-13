<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\AuditEvent;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\MicrosoftGraphSharePointOnboardingCanary;
use App\Models\SharePointAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SharePointAttachmentTargetResolver
{
    public function __construct(private readonly MicrosoftGraphRuntimeTargetResolver $runtimeTarget) {}

    /** @return array{site_id: string, drive_id: string, target: string} */
    public function forNewAttachment(): array
    {
        if (! $this->runtimeTarget->requiresManagedTarget()) {
            return $this->legacyWorkSchedulesTarget();
        }

        $target = $this->businessAttachmentsWriteTarget();

        if ($target === null) {
            throw new MicrosoftGraphApiException('graph_business_attachments_target_not_ready', 503);
        }

        return [
            'site_id' => $target->business_attachments_site_id,
            'drive_id' => $target->business_attachments_drive_id,
            'target' => SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS,
        ];
    }

    /** @return array{site_id: string, drive_id: string, target: string} */
    public function forBusinessAttachmentMigration(): array
    {
        $target = $this->businessAttachmentsTargetForMigration();

        return [
            'site_id' => $target->business_attachments_site_id,
            'drive_id' => $target->business_attachments_drive_id,
            'target' => SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS,
        ];
    }

    public function businessAttachmentsTargetForMigration(): MicrosoftGraphOnboardingTarget
    {
        $target = $this->verifiedBusinessTarget();

        if ($target === null) {
            throw new MicrosoftGraphApiException('graph_business_attachments_target_not_ready', 503);
        }

        return $target;
    }

    public function assertAttachmentWriteEnabled(SharePointAttachment $attachment): void
    {
        if (! $this->runtimeTarget->requiresManagedTarget()) {
            if (! config('services.microsoft_graph.sharepoint_enabled')) {
                throw new MicrosoftGraphApiException('graph_sharepoint_storage_disabled', 503);
            }

            return;
        }

        $target = $this->businessAttachmentsWriteTarget();

        if (
            $target === null ||
            $attachment->sharepoint_target !== SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS ||
            $attachment->sharepoint_site_id !== $target->business_attachments_site_id ||
            $attachment->sharepoint_drive_id !== $target->business_attachments_drive_id
        ) {
            throw new MicrosoftGraphApiException('graph_business_attachments_target_not_ready', 503);
        }
    }

    public function activateBusinessAttachmentWritesIfReady(
        User $actor,
        MicrosoftGraphOnboardingTarget $target,
    ): bool {
        return DB::transaction(function () use ($actor, $target): bool {
            $lockedTarget = MicrosoftGraphOnboardingTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTarget->business_attachments_writes_activated_at !== null) {
                return true;
            }

            if ($this->verifiedBusinessTarget()?->getKey() !== $lockedTarget->getKey()) {
                return false;
            }

            if (! $this->allAttachmentsUseBusinessTarget($lockedTarget)) {
                return false;
            }

            $lockedTarget->forceFill([
                'business_attachments_writes_activated_at' => now(),
                'business_attachments_writes_activated_by_user_id' => $actor->getKey(),
            ])->save();

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $lockedTarget->getMorphClass(),
                'subject_id' => $lockedTarget->getKey(),
                'event' => 'microsoft_graph_business_attachments_writes_activated',
                'summary' => $actor->name.' enabled the verified Business Attachments target after reconciliation.',
                'metadata' => [
                    'environment' => $lockedTarget->environment,
                    'target_id' => $lockedTarget->getKey(),
                    'business_site_id' => $lockedTarget->business_attachments_site_id,
                    'business_drive_id' => $lockedTarget->business_attachments_drive_id,
                    'sharepoint_attachment_count' => SharePointAttachment::query()->count(),
                    'actor_user_id' => $actor->getKey(),
                ],
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /** @return array{site_id: string, drive_id: string, target: string} */
    private function legacyWorkSchedulesTarget(): array
    {
        $siteId = config('services.microsoft_graph.work_schedules.site_id');
        $driveId = config('services.microsoft_graph.work_schedules.drive_id');

        if (! is_string($siteId) || trim($siteId) === '' || ! is_string($driveId) || trim($driveId) === '') {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_configuration_incomplete', 503);
        }

        return [
            'site_id' => trim($siteId),
            'drive_id' => trim($driveId),
            'target' => SharePointAttachment::TARGET_WORK_SCHEDULES,
        ];
    }

    private function businessAttachmentsWriteTarget(): ?MicrosoftGraphOnboardingTarget
    {
        $target = $this->verifiedBusinessTarget();

        return $target !== null && $target->business_attachments_writes_activated_at !== null
            ? $target
            : null;
    }

    private function verifiedBusinessTarget(): ?MicrosoftGraphOnboardingTarget
    {
        $target = $this->runtimeTarget->activeTarget();

        if (
            $target === null ||
            ! is_string($target->business_attachments_site_id) || trim($target->business_attachments_site_id) === '' ||
            ! is_string($target->business_attachments_drive_id) || trim($target->business_attachments_drive_id) === ''
        ) {
            return null;
        }

        $canary = MicrosoftGraphSharePointOnboardingCanary::query()
            ->where('target_id', $target->getKey())
            ->where('environment', $target->environment)
            ->where('tenant_id', $target->tenant_id)
            ->where('client_id', $target->client_id)
            ->where('site_id', $target->business_attachments_site_id)
            ->where('drive_id', $target->business_attachments_drive_id)
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (
            $canary === null ||
            $canary->created_remotely_at === null ||
            ! in_array($canary->status, [
                MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANED,
                MicrosoftGraphSharePointOnboardingCanary::STATUS_MANUALLY_RECONCILED,
            ], true)
        ) {
            return null;
        }

        $outstandingCanary = MicrosoftGraphSharePointOnboardingCanary::query()
            ->where('target_id', $target->getKey())
            ->whereIn('status', MicrosoftGraphSharePointOnboardingCanary::ACTIVE_STATUSES)
            ->exists();

        return $outstandingCanary ? null : $target;
    }

    private function allAttachmentsUseBusinessTarget(MicrosoftGraphOnboardingTarget $target): bool
    {
        return ! SharePointAttachment::query()
            ->where(function ($query) use ($target): void {
                $query->whereNull('sharepoint_site_id')
                    ->orWhere('sharepoint_site_id', '<>', $target->business_attachments_site_id)
                    ->orWhere('sharepoint_drive_id', '<>', $target->business_attachments_drive_id)
                    ->orWhere('sharepoint_target', '<>', SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS);
            })
            ->exists();
    }
}
