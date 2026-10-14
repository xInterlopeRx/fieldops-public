<?php

namespace App\Http\Controllers;

use App\Exceptions\MicrosoftGraphApiException;
use App\Http\Requests\ActivateMicrosoftGraphRuntimeTargetRequest;
use App\Http\Requests\ConfirmMicrosoftGraphRuntimeVisitOwnershipRequest;
use App\Http\Requests\FinalizeMicrosoftGraphReadinessRequest;
use App\Http\Requests\ReconcileMicrosoftGraphRuntimeTargetRecordRequest;
use App\Http\Requests\RunMicrosoftGraphOnboardingCanaryRequest;
use App\Http\Requests\SendMicrosoftGraphLinkbackRequest;
use App\Http\Requests\StoreMicrosoftGraphOnboardingTargetRequest;
use App\Http\Requests\StoreMicrosoftGraphPermissionVerificationRequest;
use App\Http\Requests\ValidateMicrosoftGraphOnboardingTargetsRequest;
use App\Http\Requests\ValidateMicrosoftGraphPermissionBoundaryRequest;
use App\Models\ApplicationSetupStep;
use App\Models\GraphOnboardingChallenge;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingCanary as GraphOnboardingCanary;
use App\Models\MicrosoftGraphOnboardingPermissionVerification;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\MicrosoftGraphSharePointOnboardingCanary as SharePointCanary;
use App\Services\ActivateMicrosoftGraphRuntimeTarget;
use App\Services\OnboardingReadinessService;
use App\Services\ReconcileMicrosoftGraphRuntimeTargetRecord;
use App\Services\RecordMicrosoftGraphPermissionVerification;
use App\Services\RunMicrosoftGraphOnboardingCanary;
use App\Services\RunMicrosoftGraphSharePointCanary;
use App\Services\SaveMicrosoftGraphOnboardingTargets;
use App\Services\SendMicrosoftGraphLinkbackChallenge;
use App\Services\ValidateMicrosoftGraphOnboardingTargets;
use App\Services\ValidateMicrosoftGraphPermissionBoundary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MicrosoftGraphOnboardingTargetController extends Controller
{
    public function show(
        Request $request,
        OnboardingReadinessService $readiness,
        ReconcileMicrosoftGraphRuntimeTargetRecord $runtimeReconciliation,
    ): Response {
        $onboardingWritesEnabled = (bool) config('services.microsoft_graph.onboarding_writes_enabled');
        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->first();
        $claim = InstallationClaim::query()->whereKey(1)->first();
        $linkback = $target === null
            ? null
            : GraphOnboardingChallenge::query()
                ->where('target_id', $target->getKey())
                ->where('user_id', $request->user()->getKey())
                ->latest('created_at')
                ->first();
        $canSendLinkback = $onboardingWritesEnabled
            && $target !== null
            && $target->target_is_dedicated_shared_mailbox
            && $target->read_access_verified_at !== null
            && in_array($target->validation_status, ['read_verified', 'read_verified_partial'], true)
            && (string) $claim?->claimed_by_user_id === (string) $request->user()->getKey();
        $canValidateBoundary = $target !== null
            && $target->target_is_dedicated_shared_mailbox
            && $target->non_target_mailbox_email !== null
            && $target->non_target_is_user_mailbox
            && $target->non_target_site_id !== null
            && $target->non_target_drive_id !== null
            && $target->read_access_verified_at !== null
            && in_array($target->validation_status, ['read_verified', 'read_verified_partial'], true)
            && (string) $claim?->claimed_by_user_id === (string) $request->user()->getKey();
        $hasVerifiedFirstAdminLinkback = $target !== null
            && $claim?->claimed_by_user_id !== null
            && GraphOnboardingChallenge::query()
                ->where('target_id', $target->getKey())
                ->where('environment', app()->environment())
                ->where('user_id', $claim->claimed_by_user_id)
                ->where('status', GraphOnboardingChallenge::STATUS_VERIFIED)
                ->exists();
        $outstandingCanaries = $target === null
            ? collect()
            : GraphOnboardingCanary::query()
                ->with('target')
                ->where('target_id', $target->getKey())
                ->where('resource_type', GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT)
                ->whereIn('status', [
                    GraphOnboardingCanary::STATUS_CREATING,
                    GraphOnboardingCanary::STATUS_CREATE_UNKNOWN,
                    GraphOnboardingCanary::STATUS_CREATED,
                    GraphOnboardingCanary::STATUS_CLEANUP_PENDING,
                    GraphOnboardingCanary::STATUS_CLEANUP_FAILED,
                    GraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN,
                    GraphOnboardingCanary::STATUS_CLEANUP_CONFLICT,
                ])
                ->oldest('created_at')
                ->limit(10)
                ->get();
        $outstandingContactsCanaries = $target === null
            ? collect()
            : GraphOnboardingCanary::query()
                ->with('target')
                ->where('target_id', $target->getKey())
                ->where('resource_type', GraphOnboardingCanary::RESOURCE_CONTACT)
                ->whereIn('status', GraphOnboardingCanary::ACTIVE_STATUSES)
                ->oldest('created_at')
                ->limit(10)
                ->get();
        $lastCalendarCanary = $target === null
            ? null
            : GraphOnboardingCanary::query()
                ->with('target')
                ->where('target_id', $target->getKey())
                ->where('resource_type', GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT)
                ->latest('created_at')
                ->latest('id')
                ->first();
        $lastContactsCanary = $target === null
            ? null
            : GraphOnboardingCanary::query()
                ->with('target')
                ->where('target_id', $target->getKey())
                ->where('resource_type', GraphOnboardingCanary::RESOURCE_CONTACT)
                ->latest('created_at')
                ->latest('id')
                ->first();
        $outstandingSharePointCanaries = $target === null
            ? collect()
            : SharePointCanary::query()
                ->with('target')
                ->where('target_id', $target->getKey())
                ->whereIn('status', SharePointCanary::ACTIVE_STATUSES)
                ->oldest('created_at')
                ->limit(10)
                ->get();
        $lastSharePointCanary = $target === null
            ? null
            : SharePointCanary::query()
                ->with('target')
                ->where('target_id', $target->getKey())
                ->latest('created_at')
                ->latest('id')
                ->first();
        $permissionVerification = $target === null
            ? null
            : $this->permissionVerificationFor($target)->latest('verified_at')->latest('id')->first();
        $canCreateCalendarCanary = $onboardingWritesEnabled
            && $canValidateBoundary
            && $target->boundary_verified_at !== null
            && $outstandingCanaries->isEmpty()
            && $outstandingContactsCanaries->isEmpty()
            && $outstandingSharePointCanaries->isEmpty();
        $canCreateContactCanary = $onboardingWritesEnabled
            && $canValidateBoundary
            && $target->contacts_boundary_verified_at !== null
            && $target->target_contacts_status === 'accessible'
            && $target->non_target_contacts_status === 'denied'
            && $outstandingCanaries->isEmpty()
            && $outstandingContactsCanaries->isEmpty()
            && $outstandingSharePointCanaries->isEmpty();
        $canCreateSharePointCanary = $onboardingWritesEnabled
            && $target !== null
            && $target->validation_status === 'read_verified'
            && $target->business_attachments_site_id !== null
            && $target->business_attachments_drive_id !== null
            && $permissionVerification !== null
            && $this->matchesPermissionVerification($permissionVerification, $target)
            && $outstandingCanaries->isEmpty()
            && $outstandingContactsCanaries->isEmpty()
            && $outstandingSharePointCanaries->isEmpty();
        $canRecordPermissionVerification = $target !== null
            && $target->target_is_dedicated_shared_mailbox
            && $target->non_target_is_user_mailbox
            && $target->validation_status === 'read_verified'
            && $target->read_access_verified_at !== null
            && $target->business_attachments_site_id !== null
            && $target->business_attachments_drive_id !== null
            && $target->boundary_verified_at !== null
            && $target->non_target_mailbox_calendar_status === 'denied'
            && $target->non_target_site_status === 'denied'
            && $target->non_target_drive_status === 'denied'
            && $target->contacts_boundary_verified_at !== null
            && $target->target_contacts_status === 'accessible'
            && $target->non_target_contacts_status === 'denied'
            && $hasVerifiedFirstAdminLinkback;
        $readinessSnapshot = $readiness->snapshot();
        $graphReadinessStep = collect($readinessSnapshot['steps'])
            ->firstWhere('key', ApplicationSetupStep::GRAPH_VALIDATION);
        $runtimeReconciliationSnapshot = $runtimeReconciliation->snapshot();
        $canActivateGraphRuntime = $target !== null
            && $target->runtime_activated_at === null
            && ($graphReadinessStep['status'] ?? null) === ApplicationSetupStep::STATUS_PASSED
            && ($permissionVerification?->matchesTarget($target) ?? false)
            && $readinessSnapshot['invitationsUnlocked']
            && $runtimeReconciliationSnapshot['visitCount'] === 0
            && $runtimeReconciliationSnapshot['contactCount'] === 0
            && $runtimeReconciliationSnapshot['inFlightVisitCount'] === 0
            && $runtimeReconciliationSnapshot['inFlightContactCount'] === 0
            && $runtimeReconciliationSnapshot['unresolvedMailCount'] === 0
            && (string) $claim?->claimed_by_user_id === (string) $request->user()->getKey();

        return Inertia::render('Admin/MicrosoftGraphOnboardingTargets', [
            'environment' => app()->environment(),
            'onboardingWritesEnabled' => $onboardingWritesEnabled,
            'target' => $target === null ? null : [
                'tenant_id' => $target->tenant_id,
                'client_id' => $target->client_id,
                'notification_mailbox_id' => $target->notification_mailbox_id,
                'notification_mailbox_address' => $target->notification_mailbox_address,
                'target_is_dedicated_shared_mailbox' => $target->target_is_dedicated_shared_mailbox,
                'notification_calendar_id' => $target->notification_calendar_id,
                'work_schedules_group_id' => $target->work_schedules_group_id,
                'work_schedules_site_id' => $target->work_schedules_site_id,
                'work_schedules_drive_id' => $target->work_schedules_drive_id,
                'business_attachments_site_id' => $target->business_attachments_site_id,
                'business_attachments_drive_id' => $target->business_attachments_drive_id,
                'non_target_mailbox_email' => $target->non_target_mailbox_email,
                'non_target_is_user_mailbox' => $target->non_target_is_user_mailbox,
                'runtimeActivatedAt' => $target->runtime_activated_at?->toIso8601String(),
                'non_target_site_id' => $target->non_target_site_id,
                'non_target_drive_id' => $target->non_target_drive_id,
                'configuredAt' => $target->configured_at?->toIso8601String(),
                'validationStatus' => $target->validation_status,
                'lastValidationAttemptedAt' => $target->last_validation_attempted_at?->toIso8601String(),
                'readAccessVerifiedAt' => $target->read_access_verified_at?->toIso8601String(),
                'readAccessCheckCount' => $target->read_access_check_count,
                'lastErrorCode' => $target->last_error_code,
                'boundaryValidationAttemptedAt' => $target->boundary_validation_attempted_at?->toIso8601String(),
                'boundaryVerifiedAt' => $target->boundary_verified_at?->toIso8601String(),
                'boundaryErrorCode' => $target->boundary_error_code,
                'nonTargetMailboxCalendarStatus' => $target->non_target_mailbox_calendar_status,
                'nonTargetSiteStatus' => $target->non_target_site_status,
                'nonTargetDriveStatus' => $target->non_target_drive_status,
                'targetContactsStatus' => $target->target_contacts_status,
                'nonTargetContactsStatus' => $target->non_target_contacts_status,
                'contactsBoundaryValidationAttemptedAt' => $target->contacts_boundary_validation_attempted_at?->toIso8601String(),
                'contactsBoundaryVerifiedAt' => $target->contacts_boundary_verified_at?->toIso8601String(),
                'contactsBoundaryErrorCode' => $target->contacts_boundary_error_code,
                'permissionVerification' => $permissionVerification === null ? null : [
                    'id' => $permissionVerification->getKey(),
                    'm365AdminUpn' => $permissionVerification->m365_admin_upn,
                    'verificationReference' => $permissionVerification->verification_reference,
                    'nonTargetMailboxEmail' => $permissionVerification->non_target_mailbox_email,
                    'targetIsDedicatedSharedMailbox' => $permissionVerification->target_is_dedicated_shared_mailbox,
                    'nonTargetIsUserMailbox' => $permissionVerification->non_target_is_user_mailbox,
                    'verifiedAt' => $permissionVerification->verified_at?->toIso8601String(),
                ],
            ],
            'canSendLinkback' => $canSendLinkback,
            'canValidateBoundary' => $canValidateBoundary,
            'canCreateCalendarCanary' => $canCreateCalendarCanary,
            'canCreateContactCanary' => $canCreateContactCanary,
            'canCreateSharePointCanary' => $canCreateSharePointCanary,
            'canRecordPermissionVerification' => $canRecordPermissionVerification,
            'canFinalizeGraphReadiness' => $readinessSnapshot['graphFinalizationAvailable'],
            'canActivateGraphRuntime' => $canActivateGraphRuntime,
            'runtimeReconciliation' => $runtimeReconciliationSnapshot,
            'graphReadinessStepStatus' => $graphReadinessStep['status'] ?? null,
            'lastSharePointCanary' => $lastSharePointCanary === null ? null : $this->sharePointCanaryPayload($lastSharePointCanary),
            'outstandingSharePointCanaries' => $outstandingSharePointCanaries->map(fn (SharePointCanary $canary): array => $this->sharePointCanaryPayload($canary))->all(),
            'lastCalendarCanary' => $lastCalendarCanary === null ? null : $this->canaryPayload($lastCalendarCanary),
            'lastContactCanary' => $lastContactsCanary === null ? null : $this->canaryPayload($lastContactsCanary),
            'outstandingCalendarCanaries' => $outstandingCanaries->map(fn (GraphOnboardingCanary $canary): array => $this->canaryPayload($canary))->all(),
            'outstandingContactsCanaries' => $outstandingContactsCanaries->map(fn (GraphOnboardingCanary $canary): array => $this->canaryPayload($canary))->all(),
            'linkback' => $linkback === null ? null : [
                'status' => $linkback->status,
                'sentAt' => $linkback->sent_at?->toIso8601String(),
                'expiresAt' => $linkback->expires_at?->toIso8601String(),
                'verifiedAt' => $linkback->verified_at?->toIso8601String(),
                'failureCode' => $linkback->failure_code,
            ],
            'contactsCanaryFeedback' => session('contacts_canary_feedback'),
            'status' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(
        StoreMicrosoftGraphOnboardingTargetRequest $request,
        SaveMicrosoftGraphOnboardingTargets $saveTargets,
    ): RedirectResponse {
        $saveTargets->save($request->user(), $request->validated());

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'Target identifiers saved. No Microsoft Graph request or permission change was performed; validation remains pending.');
    }

    public function validateReadAccess(
        ValidateMicrosoftGraphOnboardingTargetsRequest $request,
        ValidateMicrosoftGraphOnboardingTargets $validateTargets,
    ): RedirectResponse {
        try {
            $target = $validateTargets->validate($request->user());
        } catch (MicrosoftGraphApiException $exception) {
            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
                ->with('error', 'Read-only Graph target checks did not complete ('.$exception->reason.'). No Graph data was written.');
        }

        $message = $target->validation_status === 'read_verified_partial'
            ? 'Read-only metadata access was confirmed for '.$target->read_access_check_count.' configured Graph targets. Business Attachments target IDs still need approval and verification; linkback and write canaries remain pending.'
            : 'Read-only metadata access was confirmed for '.$target->read_access_check_count.' Graph targets. No mailbox/file content, Graph writes, or permission changes were made; linkback and write canaries remain pending.';

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')->with('status', $message);
    }

    public function sendLinkback(
        SendMicrosoftGraphLinkbackRequest $request,
        SendMicrosoftGraphLinkbackChallenge $sendLinkback,
    ): RedirectResponse {
        try {
            $sendLinkback->send($request->user());
        } catch (MicrosoftGraphApiException $exception) {
            $message = $exception->reason === 'graph_linkback_delivery_unknown'
                ? 'Graph did not confirm whether the linkback email was accepted. Check the recipient mailbox before requesting another challenge.'
                : 'Graph did not accept the setup email linkback ('.$exception->reason.'). No customer or Job data was included.';

            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')->with('error', $message);
        }

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'Graph accepted one setup email. Delivery is not confirmed until the recipient follows the signed linkback.');
    }

    public function validatePermissionBoundary(
        ValidateMicrosoftGraphPermissionBoundaryRequest $request,
        ValidateMicrosoftGraphPermissionBoundary $validateBoundary,
    ): RedirectResponse {
        try {
            $target = $validateBoundary->validate($request->user());
        } catch (MicrosoftGraphApiException $exception) {
            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
                ->with('error', 'The non-target boundary check did not complete ('.$exception->reason.'). No Graph resources were changed.');
        }

        $resourceBoundaryVerified = $target->boundary_verified_at !== null;
        $contactsBoundaryVerified = $target->contacts_boundary_verified_at !== null;
        $allBoundariesVerified = $resourceBoundaryVerified && $contactsBoundaryVerified;
        $message = match (true) {
            $allBoundariesVerified => 'Graph denied all non-target Calendar, Site, Drive, and Contacts-folder collection requests, and returned success for the selected Contacts-folder collection request. This verifies read boundaries only; Exchange Mail.Send/Calendars.ReadWrite scope still needs the M365-admin PowerShell check. No Graph resources were changed.',
            ! $resourceBoundaryVerified => 'The Calendar/Site/Drive non-target boundary check did not establish denial for every control ('.$target->boundary_error_code.'). Review the per-resource statuses. No Graph resources were changed.',
            default => 'The selected Contacts-folder collection request did not succeed and access was denied for the non-target mailbox ('.$target->contacts_boundary_error_code.'). Review the Contacts status and M365 permission scope. No Graph resources were changed.',
        };

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with($allBoundariesVerified ? 'status' : 'error', $message);
    }

    public function storePermissionVerification(
        StoreMicrosoftGraphPermissionVerificationRequest $request,
        RecordMicrosoftGraphPermissionVerification $recordVerification,
    ): RedirectResponse {
        $recordVerification->record($request->user(), $request->safe()->only([
            'm365_admin_upn',
            'verification_reference',
        ]));

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'M365-admin scope results were recorded for the exact saved tenant, app, mailbox, Business Attachments Site, and Drive. This is an Admin attestation, not an independent FieldOps validation.');
    }

    public function createSharePointCanary(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        RunMicrosoftGraphSharePointCanary $runCanary,
    ): RedirectResponse {
        try {
            $canary = $runCanary->createAndCleanup($request->user());
        } catch (MicrosoftGraphApiException $exception) {
            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
                ->with('error', 'The SharePoint canary did not complete ('.$exception->reason.'). Review the saved canary status before retrying.');
        }

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with($canary->status === SharePointCanary::STATUS_CLEANED ? 'status' : 'error', $canary->status === SharePointCanary::STATUS_CLEANED
                ? 'The temporary Business Attachments folder was created and conditionally deleted.'
                : 'The temporary SharePoint canary was created but cleanup needs attention ('.$canary->failure_code.'). Do not retry creation; review the reconciliation item.');
    }

    public function retrySharePointCanaryCleanup(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        SharePointCanary $canary,
        RunMicrosoftGraphSharePointCanary $runCanary,
    ): RedirectResponse {
        try {
            $canary = $runCanary->retryCleanup($request->user(), $canary);
        } catch (MicrosoftGraphApiException $exception) {
            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
                ->with('error', 'Conditional SharePoint canary cleanup did not complete ('.$exception->reason.').');
        }

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with($canary->status === SharePointCanary::STATUS_CLEANED ? 'status' : 'error', $canary->status === SharePointCanary::STATUS_CLEANED
                ? 'The SharePoint canary was conditionally deleted or was already absent.'
                : 'The SharePoint canary remains unresolved ('.$canary->failure_code.'). Use manual M365 reconciliation.');
    }

    public function manuallyReconcileSharePointCanary(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        SharePointCanary $canary,
        RunMicrosoftGraphSharePointCanary $runCanary,
    ): RedirectResponse {
        $runCanary->manuallyReconcile($request->user(), $canary);

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'Manual reconciliation recorded. The first Admin confirmed the exact marker folder was absent or removed after inspecting the saved Drive; this does not assert that FieldOps deleted it.');
    }

    public function finalizeReadiness(
        FinalizeMicrosoftGraphReadinessRequest $request,
        OnboardingReadinessService $readiness,
    ): RedirectResponse {
        $readiness->finalizeGraphValidation($request->user());

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'All current Graph onboarding checks were revalidated and the Graph readiness step was finalized by the first Admin.');
    }

    public function activateRuntimeTarget(
        ActivateMicrosoftGraphRuntimeTargetRequest $request,
        ActivateMicrosoftGraphRuntimeTarget $activateRuntimeTarget,
    ): RedirectResponse {
        $activateRuntimeTarget->activate($request->user());

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'The verified shared mailbox is now the active runtime target. Graph writes remain controlled by their separate deployment flags.');
    }

    public function reconcileRuntimeVisit(
        ReconcileMicrosoftGraphRuntimeTargetRecordRequest $request,
        int $visit,
        ReconcileMicrosoftGraphRuntimeTargetRecord $reconcile,
    ): RedirectResponse {
        $reconcile->visit($request->user(), $visit, $request->safe()->only([
            'm365_admin_upn',
            'verification_reference',
        ]));

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'The legacy Visit event pointer was cleared after your M365-admin reconciliation confirmation. No Graph request or remote deletion was performed.');
    }

    public function confirmRuntimeVisitOwnership(
        ConfirmMicrosoftGraphRuntimeVisitOwnershipRequest $request,
        int $visit,
        ReconcileMicrosoftGraphRuntimeTargetRecord $reconcile,
    ): RedirectResponse {
        $reconcile->confirmVisitEventOwnership($request->user(), $visit, $request->safe()->only([
            'm365_admin_upn',
            'verification_reference',
        ]));

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'M365-admin ownership confirmation recorded. The Visit event ID was retained; no Graph request was made.');
    }

    public function retireActiveRuntimeVisitEvent(
        ReconcileMicrosoftGraphRuntimeTargetRecordRequest $request,
        int $visit,
        ReconcileMicrosoftGraphRuntimeTargetRecord $reconcile,
    ): RedirectResponse {
        $reconcile->retireActiveVisitEventPointer($request->user(), $visit, $request->safe()->only([
            'm365_admin_upn',
            'verification_reference',
        ]));

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'Remote Visit event reconciliation recorded. Its local pointer was cleared; no Graph request or remote deletion was performed.');
    }

    public function reconcileRuntimeContact(
        ReconcileMicrosoftGraphRuntimeTargetRecordRequest $request,
        int $contact,
        ReconcileMicrosoftGraphRuntimeTargetRecord $reconcile,
    ): RedirectResponse {
        $reconcile->contact($request->user(), $contact, $request->safe()->only([
            'm365_admin_upn',
            'verification_reference',
        ]));

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'The legacy Outlook Contact pointer was cleared after your M365-admin reconciliation confirmation. No Graph request or remote deletion was performed.');
    }

    public function createCalendarCanary(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        RunMicrosoftGraphOnboardingCanary $runCanary,
    ): RedirectResponse {
        try {
            $canary = $runCanary->createCalendarAndCleanup($request->user());
        } catch (MicrosoftGraphApiException $exception) {
            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
                ->with('error', 'The Calendar canary did not complete ('.$exception->reason.'). Review the saved canary status before retrying.');
        }

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with($canary->status === GraphOnboardingCanary::STATUS_CLEANED ? 'status' : 'error', $canary->status === GraphOnboardingCanary::STATUS_CLEANED
                ? 'The temporary Calendar canary was created and conditionally deleted. No attendees or business data were included.'
                : 'The temporary Calendar canary was created but cleanup needs attention ('.$canary->failure_code.'). Do not retry creation; review the reconciliation item.');
    }

    public function retryCalendarCanaryCleanup(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        GraphOnboardingCanary $canary,
        RunMicrosoftGraphOnboardingCanary $runCanary,
    ): RedirectResponse {
        try {
            $canary = $runCanary->retryCleanup($request->user(), $canary);
        } catch (MicrosoftGraphApiException $exception) {
            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
                ->with('error', 'Conditional Calendar canary cleanup did not complete ('.$exception->reason.').');
        }

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with($canary->status === GraphOnboardingCanary::STATUS_CLEANED ? 'status' : 'error', $canary->status === GraphOnboardingCanary::STATUS_CLEANED
                ? 'The Calendar canary was conditionally deleted or was already absent.'
                : 'The Calendar canary remains unresolved ('.$canary->failure_code.'). Use manual M365 reconciliation; FieldOps has not marked it clean.');
    }

    public function manuallyReconcileCalendarCanary(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        GraphOnboardingCanary $canary,
        RunMicrosoftGraphOnboardingCanary $runCanary,
    ): RedirectResponse {
        $runCanary->manuallyReconcile($request->user(), $canary);

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('status', 'Manual reconciliation recorded. The first Admin confirmed the event was absent or removed after inspecting the saved Calendar; this does not assert that FieldOps deleted it.');
    }

    public function createContactsCanary(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        RunMicrosoftGraphOnboardingCanary $runCanary,
    ): RedirectResponse {
        try {
            $canary = $runCanary->createContactAndCleanup($request->user());
        } catch (MicrosoftGraphApiException $exception) {
            $message = $exception->reason === 'graph_permission_denied'
                ? 'Microsoft Graph denied the Contacts canary create (graph_permission_denied). No Contact was created. Check the selected-mailbox Contacts.ReadWrite scope and its propagation before retrying.'
                : 'The Contacts canary did not complete ('.$exception->reason.'). Review its saved status below before deciding whether to retry or reconcile.';

            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
                ->with('contacts_canary_feedback', ['type' => 'error', 'message' => $message]);
        }

        $cleaned = $canary->status === GraphOnboardingCanary::STATUS_CLEANED;

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')->with('contacts_canary_feedback', [
            'type' => $cleaned ? 'success' : 'error',
            'message' => $cleaned
                ? 'Success: the temporary Contact was created and conditionally deleted. No personal, Customer, or Job data was included.'
                : 'The temporary Contact was created but cleanup needs attention ('.$canary->failure_code.'). Do not retry creation; use the cleanup or reconciliation action below.',
        ]);
    }

    public function retryContactsCanaryCleanup(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        GraphOnboardingCanary $canary,
        RunMicrosoftGraphOnboardingCanary $runCanary,
    ): RedirectResponse {
        try {
            $canary = $runCanary->retryCleanup($request->user(), $canary);
        } catch (MicrosoftGraphApiException $exception) {
            return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')->with('contacts_canary_feedback', [
                'type' => 'error',
                'message' => 'Conditional Contacts canary cleanup did not complete ('.$exception->reason.'). Review the saved status below.',
            ]);
        }

        $cleaned = $canary->status === GraphOnboardingCanary::STATUS_CLEANED;

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')->with('contacts_canary_feedback', [
            'type' => $cleaned ? 'success' : 'error',
            'message' => $cleaned
                ? 'Success: the Contacts canary was conditionally deleted or was already absent.'
                : 'The Contacts canary remains unresolved ('.$canary->failure_code.'). Use manual M365 reconciliation; FieldOps has not marked it clean.',
        ]);
    }

    public function manuallyReconcileContactsCanary(
        RunMicrosoftGraphOnboardingCanaryRequest $request,
        GraphOnboardingCanary $canary,
        RunMicrosoftGraphOnboardingCanary $runCanary,
    ): RedirectResponse {
        $runCanary->manuallyReconcile($request->user(), $canary);

        return to_route('admin.integrations.microsoft-graph.onboarding-targets.show')
            ->with('contacts_canary_feedback', [
                'type' => 'success',
                'message' => 'Manual reconciliation recorded. You confirmed the Contact was absent or removed after inspecting the saved Contacts folder; this does not assert that FieldOps deleted it.',
            ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function canaryPayload(GraphOnboardingCanary $canary): array
    {
        $onboardingWritesEnabled = (bool) config('services.microsoft_graph.onboarding_writes_enabled');
        $staleInFlight = $canary->updated_at !== null
            && $canary->updated_at->lte(now()->subMinutes(5));
        $currentTarget = $canary->target;
        $sameTarget = $currentTarget !== null
            && $currentTarget->tenant_id === $canary->tenant_id
            && $currentTarget->client_id === $canary->client_id
            && $currentTarget->notification_mailbox_id === $canary->mailbox_id
            && ($canary->resource_type === GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT
                ? $currentTarget->notification_calendar_id === $canary->calendar_id
                : $canary->resource_type === GraphOnboardingCanary::RESOURCE_CONTACT && $canary->calendar_id === null);

        return [
            'id' => $canary->getKey(),
            'status' => $canary->status,
            'resourceType' => $canary->resource_type,
            'subject' => $canary->subject,
            'tenantId' => $canary->tenant_id,
            'clientId' => $canary->client_id,
            'mailboxId' => $canary->mailbox_id,
            'calendarId' => $canary->calendar_id,
            'remoteResourceId' => $canary->remote_resource_id,
            'attemptedAt' => $canary->attempted_at?->toIso8601String(),
            'createdRemotelyAt' => $canary->created_remotely_at?->toIso8601String(),
            'cleanedAt' => $canary->cleaned_at?->toIso8601String(),
            'reconciledAt' => $canary->reconciled_at?->toIso8601String(),
            'failureCode' => $canary->failure_code,
            'canRetryCleanup' => $onboardingWritesEnabled && (
                (in_array($canary->status, [
                    GraphOnboardingCanary::STATUS_CREATED,
                    GraphOnboardingCanary::STATUS_CLEANUP_FAILED,
                    GraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN,
                ], true) && $sameTarget)
                || ($canary->status === GraphOnboardingCanary::STATUS_CLEANUP_PENDING && $staleInFlight && $sameTarget)
            ),
            'canManuallyReconcile' => in_array($canary->status, [
                GraphOnboardingCanary::STATUS_CREATE_UNKNOWN,
                GraphOnboardingCanary::STATUS_CREATED,
                GraphOnboardingCanary::STATUS_CLEANUP_FAILED,
                GraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN,
                GraphOnboardingCanary::STATUS_CLEANUP_CONFLICT,
            ], true) || (in_array($canary->status, [
                GraphOnboardingCanary::STATUS_CREATING,
                GraphOnboardingCanary::STATUS_CLEANUP_PENDING,
            ], true) && $staleInFlight),
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function sharePointCanaryPayload(SharePointCanary $canary): array
    {
        $onboardingWritesEnabled = (bool) config('services.microsoft_graph.onboarding_writes_enabled');
        $staleInFlight = $canary->updated_at !== null
            && $canary->updated_at->lte(now()->subMinutes(5));
        $sameTarget = $canary->matchesTarget($canary->target);

        return [
            'id' => $canary->getKey(),
            'status' => $canary->status,
            'folderName' => $canary->folder_name,
            'siteId' => $canary->site_id,
            'driveId' => $canary->drive_id,
            'remoteItemId' => $canary->remote_item_id,
            'attemptedAt' => $canary->attempted_at?->toIso8601String(),
            'createdRemotelyAt' => $canary->created_remotely_at?->toIso8601String(),
            'cleanedAt' => $canary->cleaned_at?->toIso8601String(),
            'reconciledAt' => $canary->reconciled_at?->toIso8601String(),
            'failureCode' => $canary->failure_code,
            'canRetryCleanup' => $onboardingWritesEnabled && (
                (in_array($canary->status, [
                    SharePointCanary::STATUS_CREATED,
                    SharePointCanary::STATUS_CLEANUP_FAILED,
                    SharePointCanary::STATUS_CLEANUP_UNKNOWN,
                ], true) || ($canary->status === SharePointCanary::STATUS_CLEANUP_PENDING && $staleInFlight))
                && $sameTarget
            ),
            'canManuallyReconcile' => in_array($canary->status, [
                SharePointCanary::STATUS_CREATE_UNKNOWN,
                SharePointCanary::STATUS_CREATED,
                SharePointCanary::STATUS_CLEANUP_FAILED,
                SharePointCanary::STATUS_CLEANUP_UNKNOWN,
                SharePointCanary::STATUS_CLEANUP_CONFLICT,
            ], true) || (in_array($canary->status, [
                SharePointCanary::STATUS_CREATING,
                SharePointCanary::STATUS_CLEANUP_PENDING,
            ], true) && $staleInFlight),
        ];
    }

    private function permissionVerificationFor(MicrosoftGraphOnboardingTarget $target): Builder
    {
        return MicrosoftGraphOnboardingPermissionVerification::query()
            ->where('target_id', $target->getKey())
            ->where('environment', $target->environment)
            ->where('tenant_id', $target->tenant_id)
            ->where('client_id', $target->client_id)
            ->where('mailbox_id', $target->notification_mailbox_id)
            ->where('non_target_mailbox_email', $target->non_target_mailbox_email)
            ->where('target_is_dedicated_shared_mailbox', true)
            ->where('non_target_is_user_mailbox', true)
            ->where('business_site_id', $target->business_attachments_site_id)
            ->where('business_drive_id', $target->business_attachments_drive_id)
            ->where('mail_send_target_verified', true)
            ->where('mail_send_non_target_denied', true)
            ->where('calendar_write_target_verified', true)
            ->where('calendar_write_non_target_denied', true)
            ->where('contacts_boundary_verified', true)
            ->where('sharepoint_boundary_verified', true)
            ->where('sharepoint_retention_reviewed', true)
            ->where('unscoped_app_roles_reviewed', true);
    }

    private function matchesPermissionVerification(MicrosoftGraphOnboardingPermissionVerification $verification, MicrosoftGraphOnboardingTarget $target): bool
    {
        return $verification->matchesTarget($target);
    }
}
