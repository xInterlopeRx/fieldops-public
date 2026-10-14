<?php

namespace App\Services;

use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\Customer;
use App\Models\GraphOnboardingChallenge;
use App\Models\InstallationClaim;
use App\Models\IntegrationConnection;
use App\Models\MicrosoftGraphOnboardingCanary;
use App\Models\MicrosoftGraphOnboardingPermissionVerification;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\MicrosoftGraphSharePointOnboardingCanary;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksItemCatalogSync;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingReadinessService
{
    public function __construct(
        private readonly ApplicationSetupStepService $steps,
        private readonly QuickBooksApiClient $quickBooks,
        private readonly QuickBooksInitialCustomerImportService $customerSiteImport,
        private readonly QuickBooksItemCatalogSyncService $itemCatalogSync,
        private readonly SyncHealthService $syncHealth,
        private readonly SetCustomerImportMode $customerImportMode,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $state = ApplicationSetupState::query()->whereKey(1)->firstOrFail();
        $storedSteps = ApplicationSetupStep::query()->get()->keyBy('step_key');
        $importMode = $state->quickbooks_import_mode ?: ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API;
        $csvImportMode = $importMode === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV;
        $quickBooksOptedIn = (bool) $state->quickbooks_sync_opted_in;
        $itemCatalog = $this->itemCatalogSync->snapshot();

        if (! $state->onboarding_required) {
            return [
                'required' => false,
                'legacy' => true,
                'customerImportMode' => $importMode,
                'quickBooksOptedIn' => false,
                'canOptInQuickBooks' => false,
                'canOptOutQuickBooks' => false,
                'canSelectCustomerImportMode' => false,
                'ready' => true,
                'invitationsUnlocked' => true,
                'canComplete' => false,
                'graphFinalizationAvailable' => false,
                'canDeferBwsRuntimeReader' => false,
                'canResumeBwsRuntimeReader' => false,
                'canResumeInitialImport' => false,
                'canStartCustomerSiteImport' => false,
                'canPreviewItemCatalogImport' => false,
                'canStartItemCatalogImport' => false,
                'canResumeItemCatalogImport' => false,
                'canStartCsvCustomerSiteImport' => false,
                'canResumeCustomerSiteImport' => false,
                'customerSiteImport' => [
                    'status' => 'pending',
                    'customersImported' => 0,
                    'sitesImported' => 0,
                    'customersProcessed' => 0,
                    'customersTotal' => 0,
                    'failureCount' => 0,
                    'failures' => [],
                    'failureCode' => null,
                    'canResume' => false,
                    'updatedAt' => null,
                ],
                'itemCatalog' => $itemCatalog,
                'graphTargetsConfigured' => false,
                'graphValidationStatus' => null,
                'graphReadAccessVerifiedAt' => null,
                'graphReadAccessCheckCount' => 0,
                'completedAt' => $state->readiness_completed_at?->toIso8601String(),
                'completedBy' => $state->readinessCompletedBy?->name,
                'blockers' => [],
                'steps' => [],
            ];
        }

        $claim = InstallationClaim::query()->whereKey(1)->first();
        $firstAdmin = $claim?->claimedBy;
        $firstAdminReady = $firstAdmin !== null
            && $firstAdmin->status === 'active'
            && $firstAdmin->hasVerifiedEmail()
            && $firstAdmin->hasEnabledTwoFactorAuthentication();

        $bwsConnection = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();
        $bwsReady = $bwsConnection?->isVerifiedForCurrentEnvironment() ?? false;
        $bwsGraphReady = $bwsConnection?->isVerifiedForGraph() ?? false;

        try {
            $quickBooksReady = $quickBooksOptedIn
                && ! $csvImportMode
                && $this->quickBooks->isConnectedForCurrentEnvironment();
        } catch (DecryptException) {
            $quickBooksReady = false;
        }
        $importStep = $storedSteps->get(ApplicationSetupStep::INITIAL_QBO_IMPORT);
        $quickBooksOAuthStep = $storedSteps->get(ApplicationSetupStep::QUICKBOOKS_OAUTH);
        $quickBooksStepStatus = ! $quickBooksOptedIn || $csvImportMode
            ? ApplicationSetupStep::STATUS_NOT_REQUIRED
            : ($quickBooksReady
                ? ApplicationSetupStep::STATUS_PASSED
                : (in_array($quickBooksOAuthStep?->status, [ApplicationSetupStep::STATUS_FAILED, ApplicationSetupStep::STATUS_IN_PROGRESS], true)
                    ? $quickBooksOAuthStep->status
                    : ApplicationSetupStep::STATUS_PENDING));
        $customerSiteImport = $this->customerSiteImport->snapshot(
            $csvImportMode
                ? QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
                : QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API,
        );
        $initialImportStatus = match (true) {
            $csvImportMode && $customerSiteImport['status'] === 'failed' => ApplicationSetupStep::STATUS_FAILED,
            $csvImportMode && $customerSiteImport['status'] === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE
                && $customerSiteImport['failureCount'] === 0
                && $importStep?->status === ApplicationSetupStep::STATUS_PASSED => ApplicationSetupStep::STATUS_PASSED,
            $csvImportMode && $customerSiteImport['status'] === 'in_progress' => ApplicationSetupStep::STATUS_IN_PROGRESS,
            $csvImportMode => ApplicationSetupStep::STATUS_PENDING,
            ! $quickBooksOptedIn => ApplicationSetupStep::STATUS_NOT_REQUIRED,
            $customerSiteImport['status'] === 'failed'
                || $itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_FAILED => ApplicationSetupStep::STATUS_FAILED,
            $importStep?->status === ApplicationSetupStep::STATUS_DEFERRED => ApplicationSetupStep::STATUS_DEFERRED,
            $customerSiteImport['status'] === 'complete'
                && $itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_COMPLETE
                && $importStep?->status === ApplicationSetupStep::STATUS_PASSED => ApplicationSetupStep::STATUS_PASSED,
            in_array($customerSiteImport['status'], ['in_progress', 'customer_site_complete', 'complete'], true)
                || $itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS => ApplicationSetupStep::STATUS_IN_PROGRESS,
            default => ApplicationSetupStep::STATUS_PENDING,
        };
        $bwsStep = $storedSteps->get(ApplicationSetupStep::BWS_RUNTIME_READER);
        $graphStep = $storedSteps->get(ApplicationSetupStep::GRAPH_VALIDATION);
        $graphTargets = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->first();
        $graphReadVerified = in_array($graphTargets?->validation_status, ['read_verified', 'read_verified_partial'], true);
        $graphFullReadVerified = $graphTargets?->validation_status === 'read_verified'
            && $graphTargets->read_access_verified_at !== null;
        $graphBoundaryReadVerified = $graphTargets?->boundary_verified_at !== null
            && $graphTargets->non_target_mailbox_calendar_status === 'denied'
            && $graphTargets->non_target_site_status === 'denied'
            && $graphTargets->non_target_drive_status === 'denied';
        $graphContactsBoundaryReadVerified = $graphTargets?->contacts_boundary_verified_at !== null
            && $graphTargets->target_contacts_status === 'accessible'
            && $graphTargets->non_target_contacts_status === 'denied';
        $graphLinkbackVerified = $graphTargets !== null
            && $claim?->claimed_by_user_id !== null
            && GraphOnboardingChallenge::query()
                ->where('target_id', $graphTargets->getKey())
                ->where('environment', app()->environment())
                ->where('user_id', $claim->claimed_by_user_id)
                ->where('status', GraphOnboardingChallenge::STATUS_VERIFIED)
                ->exists();
        $calendarCanaryQuery = $graphTargets === null
            ? null
            : MicrosoftGraphOnboardingCanary::query()
                ->where('target_id', $graphTargets->getKey())
                ->where('resource_type', MicrosoftGraphOnboardingCanary::RESOURCE_CALENDAR_EVENT)
                ->where('tenant_id', $graphTargets->tenant_id)
                ->where('client_id', $graphTargets->client_id)
                ->where('mailbox_id', $graphTargets->notification_mailbox_id)
                ->when(
                    $graphTargets->notification_calendar_id === null,
                    fn ($query) => $query->whereNull('calendar_id'),
                    fn ($query) => $query->where('calendar_id', $graphTargets->notification_calendar_id),
                );
        $latestCalendarCanary = $calendarCanaryQuery?->latest('created_at')->latest('id')->first();
        $calendarCanaryPassed = $latestCalendarCanary !== null
            && $latestCalendarCanary->created_remotely_at !== null
            && in_array($latestCalendarCanary->status, [
                MicrosoftGraphOnboardingCanary::STATUS_CLEANED,
                MicrosoftGraphOnboardingCanary::STATUS_MANUALLY_RECONCILED,
            ], true);
        $contactsCanaryQuery = $graphTargets === null
            ? null
            : MicrosoftGraphOnboardingCanary::query()
                ->where('target_id', $graphTargets->getKey())
                ->where('resource_type', MicrosoftGraphOnboardingCanary::RESOURCE_CONTACT)
                ->where('tenant_id', $graphTargets->tenant_id)
                ->where('client_id', $graphTargets->client_id)
                ->where('mailbox_id', $graphTargets->notification_mailbox_id)
                ->whereNull('calendar_id');
        $latestContactsCanary = $contactsCanaryQuery?->latest('created_at')->latest('id')->first();
        $contactsCanaryPassed = $latestContactsCanary !== null
            && $latestContactsCanary->created_remotely_at !== null
            && in_array($latestContactsCanary->status, [
                MicrosoftGraphOnboardingCanary::STATUS_CLEANED,
                MicrosoftGraphOnboardingCanary::STATUS_MANUALLY_RECONCILED,
            ], true);
        $permissionVerification = $graphTargets === null
            ? null
            : MicrosoftGraphOnboardingPermissionVerification::query()
                ->where('target_id', $graphTargets->getKey())
                ->where('environment', $graphTargets->environment)
                ->where('tenant_id', $graphTargets->tenant_id)
                ->where('client_id', $graphTargets->client_id)
                ->where('mailbox_id', $graphTargets->notification_mailbox_id)
                ->where('non_target_mailbox_email', $graphTargets->non_target_mailbox_email)
                ->where('target_is_dedicated_shared_mailbox', true)
                ->where('non_target_is_user_mailbox', true)
                ->where('business_site_id', $graphTargets->business_attachments_site_id)
                ->where('business_drive_id', $graphTargets->business_attachments_drive_id)
                ->where('mail_send_target_verified', true)
                ->where('mail_send_non_target_denied', true)
                ->where('calendar_write_target_verified', true)
                ->where('calendar_write_non_target_denied', true)
                ->where('contacts_boundary_verified', true)
                ->where('sharepoint_boundary_verified', true)
                ->where('sharepoint_retention_reviewed', true)
                ->where('unscoped_app_roles_reviewed', true)
                ->latest('verified_at')
                ->latest('id')
                ->first();
        $permissionVerificationPassed = $permissionVerification !== null
            && $permissionVerification->matchesTarget($graphTargets);
        $sharePointCanaryQuery = $graphTargets === null
            ? null
            : MicrosoftGraphSharePointOnboardingCanary::query()
                ->where('target_id', $graphTargets->getKey())
                ->where('environment', $graphTargets->environment)
                ->where('tenant_id', $graphTargets->tenant_id)
                ->where('client_id', $graphTargets->client_id)
                ->where('site_id', $graphTargets->business_attachments_site_id)
                ->where('drive_id', $graphTargets->business_attachments_drive_id);
        $latestSharePointCanary = $sharePointCanaryQuery?->latest('created_at')->latest('id')->first();
        $sharePointCanaryPassed = $latestSharePointCanary !== null
            && $latestSharePointCanary->created_remotely_at !== null
            && in_array($latestSharePointCanary->status, [
                MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANED,
                MicrosoftGraphSharePointOnboardingCanary::STATUS_MANUALLY_RECONCILED,
            ], true);
        $outstandingCanary = $graphTargets === null ? null : MicrosoftGraphOnboardingCanary::query()
            ->where('target_id', $graphTargets->getKey())
            ->whereIn('status', MicrosoftGraphOnboardingCanary::ACTIVE_STATUSES)
            ->oldest('created_at')
            ->first();
        $outstandingSharePointCanary = $graphTargets === null ? null : MicrosoftGraphSharePointOnboardingCanary::query()
            ->where('target_id', $graphTargets->getKey())
            ->whereIn('status', MicrosoftGraphSharePointOnboardingCanary::ACTIVE_STATUSES)
            ->oldest('created_at')
            ->first();
        $canaryUnresolved = $outstandingCanary !== null || $outstandingSharePointCanary !== null;
        $canaryCleanupBlocker = $outstandingSharePointCanary !== null
            ? 'Resolve the outstanding Business Attachments SharePoint canary cleanup or manual reconciliation before Graph readiness can pass.'
            : ($outstandingCanary?->resource_type === MicrosoftGraphOnboardingCanary::RESOURCE_CONTACT
                ? 'Resolve the outstanding Contacts canary cleanup or manual reconciliation before Graph readiness can pass.'
                : 'Resolve the outstanding Calendar canary cleanup or manual reconciliation before Graph readiness can pass.');
        $graphStepReportedPassed = $graphStep?->status === ApplicationSetupStep::STATUS_PASSED;
        $graphChecksPassed = $graphFullReadVerified
            && $graphBoundaryReadVerified
            && $graphContactsBoundaryReadVerified
            && $graphLinkbackVerified
            && $calendarCanaryPassed
            && $contactsCanaryPassed
            && $permissionVerificationPassed
            && $sharePointCanaryPassed
            && ! $canaryUnresolved;
        $graphFinalizationAvailable = $firstAdminReady && $graphChecksPassed && ! $graphStepReportedPassed;
        $graphStepPassed = $graphStepReportedPassed && $graphChecksPassed;
        $calendarCanaryBlocker = 'Create and clean a Calendar canary against the current saved mailbox/calendar before Graph readiness can pass.';
        $contactsCanaryBlocker = 'Create and clean a Contacts canary against the current saved mailbox before Graph readiness can pass.';
        $permissionVerificationBlocker = 'Record the M365-admin Mail.Send, Calendars.ReadWrite, Contacts, and Business Attachments permission-boundary verification for the current targets.';
        $sharePointCanaryBlocker = 'Create and conditionally clean the temporary canary folder in the approved Business Attachments Drive.';
        $graphBlockerMessage = match (true) {
            $canaryUnresolved => $canaryCleanupBlocker,
            ! $firstAdminReady => 'Verify the first Admin email and confirm TOTP before finalizing Graph readiness.',
            $graphTargets === null => 'Enter the tenant and resource targets manually before the read-only Graph validation can run.',
            ! $graphFullReadVerified => $graphTargets->validation_status === 'read_verified_partial'
                ? 'Read-only metadata access passed for the saved targets, but the separate Business Attachments target still needs approval and verification.'
                : 'Targets are saved, but Graph read-only validation has not completed. No content was read or written.',
            ! $graphBoundaryReadVerified => 'Complete explicit non-target Calendar, Site, and Drive denial probes before Graph readiness can pass.',
            ! $graphContactsBoundaryReadVerified => 'Verify a successful selected Contacts-folder collection request and denied access to the non-target Contacts-folder collection before Graph readiness can pass.',
            ! $graphLinkbackVerified => 'Complete and confirm the first Admin email linkback before Graph readiness can pass.',
            ! $calendarCanaryPassed => $calendarCanaryBlocker,
            ! $contactsCanaryPassed => $contactsCanaryBlocker,
            ! $permissionVerificationPassed => $permissionVerificationBlocker,
            ! $sharePointCanaryPassed => $sharePointCanaryBlocker,
            $graphFinalizationAvailable => 'All Graph checks are present for the saved targets. The first Admin must explicitly finalize the Graph readiness step.',
            default => 'M365-admin permission verification and all three write canaries must pass.',
        };
        $reconciliationStep = $storedSteps->get(ApplicationSetupStep::FAILURE_RECONCILIATION);
        $bwsStepStatus = $bwsReady
            ? ApplicationSetupStep::STATUS_PASSED
            : (in_array($bwsStep?->status, [
                ApplicationSetupStep::STATUS_IN_PROGRESS,
                ApplicationSetupStep::STATUS_FAILED,
                ApplicationSetupStep::STATUS_DEFERRED,
            ], true)
                ? $bwsStep->status
                : ApplicationSetupStep::STATUS_PENDING);
        $quickBooksModeStepMessage = ! $quickBooksOptedIn
            ? 'QuickBooks sync was not selected. You can opt in later; Intuit credentials, company authorization, and initial imports will then be required.'
            : ($csvImportMode
                ? 'CSV import mode is local-only. QuickBooks API synchronization and write-back are disabled.'
                : ($quickBooksReady ? null : 'Authorize the intended QuickBooks company for this environment.'));

        $steps = [
            $this->stepPayload(
                ApplicationSetupStep::FIRST_ADMIN,
                'Verify the first Admin account',
                $firstAdminReady ? ApplicationSetupStep::STATUS_PASSED : ApplicationSetupStep::STATUS_PENDING,
                $storedSteps->get(ApplicationSetupStep::FIRST_ADMIN),
                $firstAdminReady ? null : 'Verify the first Admin email and confirm TOTP.',
            ),
            $this->stepPayload(
                ApplicationSetupStep::BWS_RUNTIME_READER,
                'Verify BWS read access and required app credentials',
                $bwsStepStatus,
                $bwsStep,
                $bwsReady
                    ? null
                    : ($bwsStepStatus === ApplicationSetupStep::STATUS_DEFERRED
                        ? 'BWS setup is paused. Invitations and Microsoft Graph remain locked until the selected credentials are verified.'
                        : ($csvImportMode
                            ? 'Configure a Can Read token and verify the two fixed Microsoft Graph secrets in this environment project.'
                            : ($quickBooksOptedIn
                                ? 'After opting in, configure or re-enter a Can Read token and verify all four fixed Intuit/Graph secrets in this environment project.'
                                : 'Configure a Can Read token and verify the two fixed Microsoft Graph secrets. QuickBooks credentials are not required until you opt in.'))),
            ),
            $this->stepPayload(
                ApplicationSetupStep::QUICKBOOKS_OAUTH,
                'Connect the QuickBooks company',
                $quickBooksStepStatus,
                $quickBooksOAuthStep,
                $quickBooksModeStepMessage,
            ),
            $this->stepPayload(
                ApplicationSetupStep::INITIAL_QBO_IMPORT,
                $csvImportMode
                    ? 'Initial Customer/Site CSV import'
                    : 'Initial QuickBooks Customer/Site/Item import',
                $initialImportStatus,
                $importStep,
                $csvImportMode
                    ? ($initialImportStatus === ApplicationSetupStep::STATUS_PASSED
                        ? null
                        : ($initialImportStatus === ApplicationSetupStep::STATUS_PENDING
                            ? 'Preview and import Customer/Site data from CSV. This local-only import is separate from QuickBooks synchronization.'
                            : $this->initialImportMessage($customerSiteImport, $itemCatalog, $initialImportStatus, $importStep, true)))
                    : (! $quickBooksOptedIn
                        ? 'QuickBooks Customer/Site and Item imports are optional and may be completed later by opting into QuickBooks sync.'
                        : $this->initialImportMessage($customerSiteImport, $itemCatalog, $initialImportStatus, $importStep, false)),
            ),
            $this->stepPayload(
                ApplicationSetupStep::GRAPH_VALIDATION,
                'Verify Microsoft Graph targets and canaries',
                $graphStepPassed
                    ? ApplicationSetupStep::STATUS_PASSED
                    : ($graphFinalizationAvailable
                        ? ApplicationSetupStep::STATUS_IN_PROGRESS
                        : ($graphStepReportedPassed || $canaryUnresolved
                            ? ApplicationSetupStep::STATUS_FAILED
                            : ($graphTargets !== null && in_array($graphStep?->status, [ApplicationSetupStep::STATUS_PENDING, ApplicationSetupStep::STATUS_IN_PROGRESS], true)
                                ? ApplicationSetupStep::STATUS_IN_PROGRESS
                                : $graphStep?->status ?? ApplicationSetupStep::STATUS_PENDING))),
                $graphStep,
                $graphStepPassed
                    ? null
                    : $graphBlockerMessage,
            ),
            $this->stepPayload(
                ApplicationSetupStep::FAILURE_RECONCILIATION,
                'Reconcile provider failure queues',
                $reconciliationStep?->status ?? ApplicationSetupStep::STATUS_PENDING,
                $reconciliationStep,
                $reconciliationStep?->status === ApplicationSetupStep::STATUS_PASSED
                    ? null
                    : 'Run the reconciliation check after required provider setup and resolve all outstanding failed or pending work.',
            ),
        ];

        $blockers = collect($steps)
            ->reject(fn (array $step): bool => $step['status'] === ApplicationSetupStep::STATUS_PASSED
                || $step['status'] === ApplicationSetupStep::STATUS_NOT_REQUIRED
                || ($step['status'] === ApplicationSetupStep::STATUS_DEFERRED
                    && $step['key'] !== ApplicationSetupStep::BWS_RUNTIME_READER))
            ->map(fn (array $step): string => $step['label'])
            ->values()
            ->all();
        $ready = $blockers === [];
        $receiptExists = $state->readiness_completed_at !== null;
        $quickBooksOptedInAfterReceipt = $receiptExists
            && $state->quickbooks_sync_opted_in_at !== null
            && $state->quickbooks_sync_opted_in_at->greaterThan($state->readiness_completed_at);
        $importReady = $csvImportMode
            ? $initialImportStatus === ApplicationSetupStep::STATUS_PASSED
            : (! $quickBooksOptedIn || $quickBooksOptedInAfterReceipt || (
                $customerSiteImport['status'] === 'complete'
                && $itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_COMPLETE
                && $importStep?->status === ApplicationSetupStep::STATUS_PASSED
            ));
        $invitationBwsReady = $quickBooksOptedInAfterReceipt ? $bwsGraphReady : $bwsReady;
        $invitationsUnlocked = $receiptExists && $invitationBwsReady && $importReady;

        return [
            'required' => true,
            'legacy' => false,
            'customerImportMode' => $importMode,
            'quickBooksOptedIn' => $quickBooksOptedIn,
            'canOptInQuickBooks' => ! $quickBooksOptedIn && ! $csvImportMode,
            'canOptOutQuickBooks' => $quickBooksOptedIn && ! $state->quickbooks_sync_enabled
                && ! $this->hasQuickBooksSetupWork(),
            'canSelectCustomerImportMode' => $this->customerImportMode->snapshot()['canChange'],
            'ready' => $ready,
            'invitationsUnlocked' => $invitationsUnlocked,
            'canComplete' => $ready && ! $receiptExists,
            'graphFinalizationAvailable' => $graphFinalizationAvailable,
            'canDeferBwsRuntimeReader' => ! $bwsReady
                && in_array($bwsStepStatus, [
                    ApplicationSetupStep::STATUS_PENDING,
                    ApplicationSetupStep::STATUS_IN_PROGRESS,
                    ApplicationSetupStep::STATUS_FAILED,
                ], true),
            'canResumeBwsRuntimeReader' => $bwsStepStatus === ApplicationSetupStep::STATUS_DEFERRED,
            'canResumeInitialImport' => $quickBooksOptedIn
                && $importStep?->status === ApplicationSetupStep::STATUS_DEFERRED,
            'canStartCustomerSiteImport' => $quickBooksOptedIn && $bwsReady && $quickBooksReady
                && ! $csvImportMode
                && $customerSiteImport['status'] === 'pending'
                && $importStep?->status !== ApplicationSetupStep::STATUS_DEFERRED,
            'canPreviewItemCatalogImport' => $quickBooksOptedIn && $bwsReady && $quickBooksReady
                && ! $csvImportMode
                && $customerSiteImport['status'] === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE
                && $itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_PENDING
                && $importStep?->status !== ApplicationSetupStep::STATUS_DEFERRED,
            'canStartItemCatalogImport' => $quickBooksOptedIn && $bwsReady && $quickBooksReady
                && ! $csvImportMode
                && $customerSiteImport['status'] === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE
                && $itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_PENDING
                && $importStep?->status !== ApplicationSetupStep::STATUS_DEFERRED,
            'canResumeItemCatalogImport' => $quickBooksOptedIn && $bwsReady && $quickBooksReady
                && ! $csvImportMode
                && $customerSiteImport['status'] === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE
                && $itemCatalog['canResume'],
            'canStartCsvCustomerSiteImport' => $csvImportMode && ! $quickBooksOptedIn
                && $customerSiteImport['status'] === 'pending'
                && $importStep?->status !== ApplicationSetupStep::STATUS_DEFERRED,
            'canResumeCustomerSiteImport' => ! $quickBooksOptedIn && $customerSiteImport['canResume']
                && ($customerSiteImport['source'] === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
                    || ($bwsReady && $quickBooksReady)),
            'customerSiteImport' => $customerSiteImport,
            'itemCatalog' => $itemCatalog,
            'graphTargetsConfigured' => $graphTargets !== null,
            'graphValidationStatus' => $graphTargets?->validation_status,
            'graphReadAccessVerifiedAt' => $graphTargets?->read_access_verified_at?->toIso8601String(),
            'graphReadAccessCheckCount' => $graphTargets?->read_access_check_count ?? 0,
            'completedAt' => $state->readiness_completed_at?->toIso8601String(),
            'completedBy' => $state->readinessCompletedBy?->name,
            'blockers' => $blockers,
            'steps' => $steps,
        ];
    }

    public function invitationsUnlocked(): bool
    {
        $state = ApplicationSetupState::query()->whereKey(1)->firstOrFail();

        if (! $state->onboarding_required) {
            return true;
        }

        $connection = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        $import = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
            ->first();
        $quickBooksOptedIn = (bool) $state->quickbooks_sync_opted_in;

        if (! $quickBooksOptedIn) {
            if (! ($connection?->isVerifiedForGraph() ?? false)) {
                return false;
            }

            if ($state->readiness_completed_at === null) {
                return false;
            }

            if ($state->quickbooks_import_mode !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV) {
                return true;
            }

            $csvImport = QuickBooksInitialCustomerImport::query()
                ->where('environment', app()->environment())
                ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
                ->first();

            return $csvImport?->status === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE
                && ! $csvImport->failures()->exists()
                && ApplicationSetupStep::query()
                    ->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)
                    ->where('status', ApplicationSetupStep::STATUS_PASSED)
                    ->exists();
        }

        if ($state->readiness_completed_at !== null
            && $state->quickbooks_sync_opted_in_at !== null
            && $state->quickbooks_sync_opted_in_at->greaterThan($state->readiness_completed_at)) {
            return $connection?->isVerifiedForGraph() ?? false;
        }

        if (! ($connection?->isVerifiedForCurrentEnvironment() ?? false)) {
            return false;
        }

        $importStepPassed = ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)
            ->where('status', ApplicationSetupStep::STATUS_PASSED)
            ->exists();
        $itemCatalogComplete = QuickBooksItemCatalogSync::query()
            ->where('environment', app()->environment())
            ->where('realm_id', $connection?->realm_id)
            ->where('status', QuickBooksItemCatalogSync::STATUS_COMPLETE)
            ->exists();

        return $state->readiness_completed_at !== null
            && $import?->status === QuickBooksInitialCustomerImport::STATUS_COMPLETE
            && $itemCatalogComplete
            && $importStepPassed;
    }

    private function hasQuickBooksSetupWork(): bool
    {
        return QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
            ->exists()
            || IntegrationConnection::query()
                ->where('provider', 'quickbooks')
                ->exists()
            || Customer::query()->whereNotNull('quickbooks_id')->exists()
            || Site::query()->whereNotNull('quickbooks_id')->exists()
            || QuickBooksItemCatalogSync::query()
                ->where('environment', app()->environment())
                ->exists()
            || ApplicationSetupStep::query()
                ->where('step_key', ApplicationSetupStep::QUICKBOOKS_OAUTH)
                ->whereIn('status', [ApplicationSetupStep::STATUS_IN_PROGRESS, ApplicationSetupStep::STATUS_PASSED])
                ->exists();
    }

    /** @param array<string, mixed> $customerSiteImport
     * @param  array<string, mixed>  $itemCatalog
     */
    private function initialImportMessage(
        array $customerSiteImport,
        array $itemCatalog,
        string $status,
        ?ApplicationSetupStep $step,
        bool $csvMode,
    ): ?string {
        if ($status === ApplicationSetupStep::STATUS_PASSED) {
            return null;
        }

        if ($step?->status === ApplicationSetupStep::STATUS_DEFERRED) {
            return 'The older import deferral does not satisfy the readiness gate. Resume setup to continue.';
        }

        if ($customerSiteImport['status'] === 'failed') {
            return 'The Customer/Site import stopped with '.$customerSiteImport['failureCount'].' record(s) requiring attention. Resume retries the source dataset idempotently.';
        }

        if ($itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_FAILED) {
            return 'The QuickBooks Item catalog sync stopped with '.$itemCatalog['failureCount'].' failure(s). Resume the sync after reviewing the sanitized failure codes.';
        }

        if ($itemCatalog['status'] === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS) {
            return 'The QuickBooks Item catalog is syncing in bounded, resumable batches. Financial fields are discarded and never stored.';
        }

        if ($customerSiteImport['status'] === 'in_progress') {
            return $customerSiteImport['source'] === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
                ? 'CSV Customer/Site records are importing in resumable batches. QuickBooks Items are not required while QuickBooks sync is off.'
                : 'Customer/Site records are importing in resumable batches.';
        }

        if ($customerSiteImport['status'] === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE) {
            return $csvMode
                ? 'CSV Customer/Site import is complete. QuickBooks Items are not required while QuickBooks sync is off; the remaining setup readiness checks still apply.'
                : 'Customer/Site import is complete. Preview and confirm the read-only QuickBooks Item catalog sync to continue.';
        }

        if ($customerSiteImport['status'] === 'complete') {
            return 'The import step is not recorded as passed. Reconcile setup status before recording readiness.';
        }

        return $csvMode
            ? 'Preview and import Customer/Site data from CSV. This local-only import is separate from QuickBooks synchronization; QuickBooks Items are not required while sync is off.'
            : 'Preview and explicitly start the bounded QuickBooks Customer/Site import. The Item catalog sync follows after that phase.';
    }

    public function finalizeGraphValidation(User $actor): void
    {
        $claim = InstallationClaim::query()->whereKey(1)->firstOrFail();

        if ((string) $claim->claimed_by_user_id !== (string) $actor->getKey()
            || $actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasEnabledTwoFactorAuthentication()
            || ! $actor->can('integrations.manage')) {
            abort(403);
        }

        DB::transaction(function () use ($actor): void {
            MicrosoftGraphOnboardingTarget::query()
                ->where('environment', app()->environment())
                ->lockForUpdate()
                ->firstOrFail();

            $snapshot = $this->snapshot();

            if (! $snapshot['graphFinalizationAvailable']) {
                throw ValidationException::withMessages([
                    'confirm_finalize_graph' => 'Graph readiness cannot be finalized until every current target, permission, linkback, canary, and cleanup check passes.',
                ]);
            }

            $this->steps->pass(ApplicationSetupStep::GRAPH_VALIDATION, $actor);

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => ApplicationSetupState::class,
                'subject_id' => 1,
                'event' => 'microsoft_graph_onboarding_readiness_finalized',
                'summary' => $actor->name.' finalized Microsoft Graph onboarding readiness after reviewing saved verification evidence.',
                'metadata' => [
                    'environment' => app()->environment(),
                    'step_key' => ApplicationSetupStep::GRAPH_VALIDATION,
                    'permission_verification_required' => true,
                    'calendar_canary_required' => true,
                    'contacts_canary_required' => true,
                    'sharepoint_canary_required' => true,
                ],
                'created_at' => now(),
            ]);
        });
    }

    public function deferBwsRuntimeReader(User $actor): void
    {
        $snapshot = $this->snapshot();

        abort_unless($snapshot['required'], 404);

        if (BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first()?->isVerifiedForCurrentEnvironment()) {
            throw ValidationException::withMessages([
                'onboarding' => 'BWS is already verified. Keep the required app-secret gate active until setup can proceed.',
            ]);
        }

        $this->steps->deferBwsRuntimeReader($actor);
    }

    public function resumeBwsRuntimeReader(User $actor): void
    {
        $snapshot = $this->snapshot();

        abort_unless($snapshot['required'], 404);

        if (! $snapshot['canResumeBwsRuntimeReader']) {
            throw ValidationException::withMessages([
                'onboarding' => 'BWS setup is not currently deferred.',
            ]);
        }

        $this->steps->resumeBwsRuntimeReader($actor);
    }

    public function resumeInitialImport(User $actor): void
    {
        $snapshot = $this->snapshot();

        abort_unless($snapshot['required'], 404);
        $stepsByKey = collect($snapshot['steps'])->keyBy('key');

        if (($stepsByKey->get(ApplicationSetupStep::BWS_RUNTIME_READER)['status'] ?? null) !== ApplicationSetupStep::STATUS_PASSED
            || ($stepsByKey->get(ApplicationSetupStep::QUICKBOOKS_OAUTH)['status'] ?? null) !== ApplicationSetupStep::STATUS_PASSED) {
            throw ValidationException::withMessages([
                'onboarding' => 'Verify the BWS runtime reader and QuickBooks connection before resuming the initial import.',
            ]);
        }

        $this->steps->resumeInitialImport($actor);
    }

    public function reconcileFailureQueues(User $actor): bool
    {
        if (! ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
            return true;
        }

        $this->steps->begin(ApplicationSetupStep::FAILURE_RECONCILIATION, $actor);

        try {
            $health = $this->syncHealth->summary();
        } catch (DecryptException) {
            $this->steps->fail(ApplicationSetupStep::FAILURE_RECONCILIATION, $actor, 'integration_connection_unavailable');

            return false;
        }

        if (($health['errors'] ?? 0) > 0 || ($health['pending'] ?? 0) > 0) {
            $this->steps->fail(ApplicationSetupStep::FAILURE_RECONCILIATION, $actor, 'unreconciled_provider_operations');

            return false;
        }

        $this->steps->pass(ApplicationSetupStep::FAILURE_RECONCILIATION, $actor);

        return true;
    }

    public function complete(User $actor): void
    {
        $this->reconcileFailureQueues($actor);

        DB::transaction(function () use ($actor): void {
            $state = ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->firstOrFail();

            if (! $state->onboarding_required || $state->readiness_completed_at !== null) {
                return;
            }

            $snapshot = $this->snapshot();

            if (! $snapshot['ready']) {
                throw ValidationException::withMessages([
                    'readiness' => 'Setup cannot be completed yet. Finish or defer each required step, verify Graph, and reconcile outstanding work.',
                ]);
            }

            $state->forceFill([
                'readiness_completed_at' => now(),
                'readiness_completed_by_user_id' => $actor->getKey(),
                'readiness_receipt_type' => 'verified',
            ])->save();
        });
    }

    /** @return array<string, mixed> */
    private function stepPayload(
        string $key,
        string $label,
        string $status,
        ?ApplicationSetupStep $step,
        ?string $message,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'lastAttemptedAt' => $step?->last_attempted_at?->toIso8601String(),
            'completedAt' => $step?->completed_at?->toIso8601String(),
            'failureCode' => $step?->failure_code,
            'attemptCount' => $step?->attempt_count ?? 0,
            'progress' => $step?->progress_total === null ? null : [
                'current' => $step->progress_current,
                'total' => $step->progress_total,
            ],
            'message' => $message,
        ];
    }
}
