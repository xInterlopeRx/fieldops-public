<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\GraphOnboardingChallenge;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingPermissionVerification;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordMicrosoftGraphPermissionVerification
{
    /** @param array<string, mixed> $evidence */
    public function record(User $actor, array $evidence): MicrosoftGraphOnboardingPermissionVerification
    {
        $this->assertFirstAdmin($actor);

        return DB::transaction(function () use ($actor, $evidence): MicrosoftGraphOnboardingPermissionVerification {
            $target = MicrosoftGraphOnboardingTarget::query()
                ->where('environment', app()->environment())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $target->target_is_dedicated_shared_mailbox
                || ! $target->non_target_is_user_mailbox
                || $target->validation_status !== 'read_verified'
                || $target->read_access_verified_at === null
                || $target->business_attachments_site_id === null
                || $target->business_attachments_drive_id === null
                || $target->boundary_verified_at === null
                || $target->non_target_mailbox_calendar_status !== 'denied'
                || $target->non_target_site_status !== 'denied'
                || $target->non_target_drive_status !== 'denied'
                || $target->contacts_boundary_verified_at === null
                || $target->target_contacts_status !== 'accessible'
                || $target->non_target_contacts_status !== 'denied'
                || ! GraphOnboardingChallenge::query()
                    ->where('target_id', $target->getKey())
                    ->where('environment', app()->environment())
                    ->where('user_id', $actor->getKey())
                    ->where('status', GraphOnboardingChallenge::STATUS_VERIFIED)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'confirm_scope_verification' => 'Complete the full selected-target preflight, read-boundary checks, and first Admin linkback before recording M365 permission verification.',
                ]);
            }

            $verification = MicrosoftGraphOnboardingPermissionVerification::query()->create([
                'id' => (string) Str::uuid(),
                'target_id' => $target->getKey(),
                'environment' => $target->environment,
                'tenant_id' => $target->tenant_id,
                'client_id' => $target->client_id,
                'mailbox_id' => $target->notification_mailbox_id,
                'non_target_mailbox_email' => $target->non_target_mailbox_email,
                'target_is_dedicated_shared_mailbox' => $target->target_is_dedicated_shared_mailbox,
                'non_target_is_user_mailbox' => $target->non_target_is_user_mailbox,
                'business_site_id' => $target->business_attachments_site_id,
                'business_drive_id' => $target->business_attachments_drive_id,
                'm365_admin_upn' => strtolower($evidence['m365_admin_upn']),
                'verification_reference' => trim($evidence['verification_reference']),
                'mail_send_target_verified' => true,
                'mail_send_non_target_denied' => true,
                'calendar_write_target_verified' => true,
                'calendar_write_non_target_denied' => true,
                'contacts_boundary_verified' => true,
                'sharepoint_boundary_verified' => true,
                'sharepoint_retention_reviewed' => true,
                'unscoped_app_roles_reviewed' => true,
                'verified_by_user_id' => $actor->getKey(),
                'verified_at' => now(),
            ]);

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->getKey(),
                'event' => 'microsoft_graph_permission_verification_recorded',
                'summary' => $actor->name.' recorded M365-admin permission verification for saved FieldOps Graph targets.',
                'metadata' => [
                    'environment' => $verification->environment,
                    'target_id' => $verification->target_id,
                    'verification_id' => $verification->getKey(),
                    'm365_admin_upn' => $verification->m365_admin_upn,
                    'verification_reference' => $verification->verification_reference,
                    'checks' => [
                        'mail_send_target_verified' => true,
                        'target_is_dedicated_shared_mailbox' => $verification->target_is_dedicated_shared_mailbox,
                        'non_target_is_user_mailbox' => $verification->non_target_is_user_mailbox,
                        'mail_send_non_target_denied' => true,
                        'calendar_write_target_verified' => true,
                        'calendar_write_non_target_denied' => true,
                        'contacts_boundary_verified' => true,
                        'sharepoint_boundary_verified' => true,
                        'sharepoint_retention_reviewed' => true,
                        'unscoped_app_roles_reviewed' => true,
                    ],
                    'externally_verified_by_admin_attestation' => true,
                ],
                'created_at' => now(),
            ]);

            return $verification;
        });
    }

    private function assertFirstAdmin(User $actor): void
    {
        $claim = InstallationClaim::query()->whereKey(1)->firstOrFail();

        if ((string) $claim->claimed_by_user_id !== (string) $actor->getKey()
            || $actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasEnabledTwoFactorAuthentication()
            || ! $actor->can('integrations.manage')) {
            abort(403);
        }
    }
}
