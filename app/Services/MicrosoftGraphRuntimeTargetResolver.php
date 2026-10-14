<?php

namespace App\Services;

use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\MicrosoftGraphOnboardingPermissionVerification;
use App\Models\MicrosoftGraphOnboardingTarget;

class MicrosoftGraphRuntimeTargetResolver
{
    public function requiresManagedTarget(): bool
    {
        $state = ApplicationSetupState::query()->whereKey(1)->first();

        return $state === null || $state->onboarding_required;
    }

    public function activeTarget(): ?MicrosoftGraphOnboardingTarget
    {
        if (! $this->requiresManagedTarget()) {
            return null;
        }

        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->whereNotNull('runtime_activated_at')
            ->first();
        $state = ApplicationSetupState::query()->whereKey(1)->first();

        if ($state?->readiness_completed_at === null
            || $target === null
            || ! $target->target_is_dedicated_shared_mailbox
            || ! $target->non_target_is_user_mailbox
            || $target->validation_status !== 'read_verified'
            || $target->read_access_verified_at === null
            || ! ApplicationSetupStep::query()
                ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
                ->where('status', ApplicationSetupStep::STATUS_PASSED)
                ->whereNotNull('completed_at')
                ->exists()) {
            return null;
        }

        $verification = MicrosoftGraphOnboardingPermissionVerification::query()
            ->where('target_id', $target->getKey())
            ->where('environment', $target->environment)
            ->where('tenant_id', $target->tenant_id)
            ->where('client_id', $target->client_id)
            ->where('mailbox_id', $target->notification_mailbox_id)
            ->where('non_target_mailbox_email', $target->non_target_mailbox_email)
            ->where('business_site_id', $target->business_attachments_site_id)
            ->where('business_drive_id', $target->business_attachments_drive_id)
            ->latest('verified_at')
            ->latest('id')
            ->first();

        return $verification !== null && $verification->matchesTarget($target)
            ? $target
            : null;
    }

    public function notificationMailboxId(): ?string
    {
        if ($this->requiresManagedTarget()) {
            return $this->activeTarget()?->notification_mailbox_id;
        }

        return $this->configuredValue(config('services.microsoft_graph.notification_mailbox_id'));
    }

    public function notificationMailboxAddress(): ?string
    {
        if ($this->requiresManagedTarget()) {
            return $this->activeTarget()?->notification_mailbox_address;
        }

        return $this->configuredValue(config('services.microsoft_graph.notification_mailbox_address'))
            ?? $this->notificationMailboxId();
    }

    public function notificationCalendarId(): ?string
    {
        if ($this->requiresManagedTarget()) {
            return $this->activeTarget()?->notification_calendar_id;
        }

        return $this->configuredValue(config('services.microsoft_graph.notification_calendar_id'));
    }

    public function tenantId(): ?string
    {
        if ($this->requiresManagedTarget()) {
            return $this->activeTarget()?->tenant_id;
        }

        return $this->configuredValue(config('services.microsoft_graph.tenant_id'));
    }

    public function clientId(): ?string
    {
        return $this->requiresManagedTarget()
            ? $this->activeTarget()?->client_id
            : null;
    }

    private function configuredValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
