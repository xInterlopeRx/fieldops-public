<?php

use App\Http\Controllers\AdminArchiveController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminCustomerWorkspaceController;
use App\Http\Controllers\AdminJobController;
use App\Http\Controllers\AdminJobWorkspaceController;
use App\Http\Controllers\AdminOnboardingAreaAccessController;
use App\Http\Controllers\AdminOnboardingController;
use App\Http\Controllers\AdminPayrollController;
use App\Http\Controllers\AdminProjectController;
use App\Http\Controllers\AdminProjectWorkspaceController;
use App\Http\Controllers\AdminSyncLogController;
use App\Http\Controllers\AdminTimeEntryBillingController;
use App\Http\Controllers\AdminTimeEntryReviewController;
use App\Http\Controllers\AdminTimeTrackingController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminVisitWorkspaceController;
use App\Http\Controllers\Api\OrganizationCalendarController;
use App\Http\Controllers\BitwardenSecretManagerConnectionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\GraphOnboardingLinkbackController;
use App\Http\Controllers\InstallationBootstrapController;
use App\Http\Controllers\LogoutAllDevicesController;
use App\Http\Controllers\MicrosoftGraphOnboardingTargetController;
use App\Http\Controllers\OpenAttachmentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QuickBooksIntegrationController;
use App\Http\Controllers\TimeEntryTimerController;
use App\Http\Controllers\TrustedDeviceController;
use App\Http\Controllers\UserInvitationController;
use App\Http\Controllers\VisitItemController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkOrderExportController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\RequireAdminOnboardingAreaAccess;
use App\Http\Middleware\RequireConfirmedTwoFactor;
use App\Models\InstallationClaim;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'canBootstrap' => InstallationClaim::query()->whereKey(1)->first()?->isAvailable() ?? false,
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return redirect()->route('calendar');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/invite/{token}', [UserInvitationController::class, 'show'])->name('invitation.accept');
Route::post('/invite/{token}', [UserInvitationController::class, 'store']);

Route::get('/setup/microsoft-graph/linkback/{challenge}', [GraphOnboardingLinkbackController::class, 'show'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('graph.onboarding.linkback.verify');
Route::post('/setup/microsoft-graph/linkback/{challenge}', [GraphOnboardingLinkbackController::class, 'verify'])
    ->middleware(['signed', 'throttle:5,1'])
    ->name('graph.onboarding.linkback.confirm');

Route::middleware('guest')->group(function (): void {
    Route::get('/bootstrap', [InstallationBootstrapController::class, 'create'])
        ->name('installation.bootstrap.create');
    Route::post('/bootstrap', [InstallationBootstrapController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('installation.bootstrap.store');
});

Route::middleware(['auth', 'verified', RequireConfirmedTwoFactor::class, 'can:work_orders.view'])->group(function () {
    Route::get('/attachments/{attachment}/preview', [OpenAttachmentController::class, 'preview'])
        ->middleware('throttle:120,1')
        ->name('attachments.preview');
    Route::get('/attachments/{attachment}/thumbnail', [OpenAttachmentController::class, 'thumbnail'])
        ->middleware('throttle:120,1')
        ->name('attachments.thumbnail');
    Route::get('/attachments/{attachment}/availability', [OpenAttachmentController::class, 'availability'])
        ->middleware('throttle:120,1')
        ->name('attachments.availability');
    Route::get('/attachments/{attachment}', OpenAttachmentController::class)
        ->name('attachments.open');
    Route::get('/visits/{visit}/items', [VisitItemController::class, 'search'])
        ->name('visits.items.search');
    Route::post('/visits/{visit}/items', [VisitItemController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('visits.items.store');
    Route::put('/visits/{visit}/items/{jobItem}', [VisitItemController::class, 'update'])
        ->scopeBindings()
        ->middleware('throttle:30,1')
        ->name('visits.items.update');
    Route::delete('/visits/{visit}/items/{jobItem}', [VisitItemController::class, 'destroy'])
        ->scopeBindings()
        ->middleware('throttle:30,1')
        ->name('visits.items.destroy');

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/admin', fn () => Inertia::render('Admin/Home'))->name('admin.home');
        Route::get('/admin/time-tracking/review', [AdminTimeEntryReviewController::class, 'index'])
            ->name('admin.time-tracking.review');
        Route::get('/admin/time-tracking', [AdminTimeTrackingController::class, 'dashboard'])
            ->name('admin.time-tracking.dashboard');
        Route::get('/admin/time-tracking/exceptions', [AdminTimeTrackingController::class, 'exceptions'])
            ->name('admin.time-tracking.exceptions');
        Route::get('/admin/time-tracking/billing', [AdminTimeEntryBillingController::class, 'index'])
            ->name('admin.time-tracking.billing');
        Route::post('/admin/time-tracking/billing/mark-invoiced', [AdminTimeEntryBillingController::class, 'bulkMarkInvoiced'])
            ->name('admin.time-tracking.billing.bulk-mark-invoiced');
        Route::post('/admin/time-tracking/billing/entries/{timeEntry}/mark-invoiced', [AdminTimeEntryBillingController::class, 'markInvoiced'])
            ->name('admin.time-tracking.billing.entries.mark-invoiced');
        Route::get('/admin/time-tracking/payroll', [AdminPayrollController::class, 'index'])
            ->name('admin.time-tracking.payroll');
        Route::get('/admin/time-tracking/payroll/export/csv', [AdminPayrollController::class, 'exportCsv'])
            ->name('admin.time-tracking.payroll.export.csv');
        Route::get('/admin/time-tracking/payroll/export/excel', [AdminPayrollController::class, 'exportExcel'])
            ->name('admin.time-tracking.payroll.export.excel');
        Route::post('/admin/time-tracking/bulk-approve', [AdminTimeEntryReviewController::class, 'bulkApprove'])
            ->name('admin.time-tracking.bulk-approve');
        Route::get('/admin/time-tracking/entries/{timeEntry}/edit', [AdminTimeEntryReviewController::class, 'edit'])
            ->name('admin.time-tracking.entries.edit');
        Route::patch('/admin/time-tracking/entries/{timeEntry}', [AdminTimeEntryReviewController::class, 'update'])
            ->name('admin.time-tracking.entries.update');
        Route::post('/admin/time-tracking/entries/{timeEntry}/approve', [AdminTimeEntryReviewController::class, 'approve'])
            ->name('admin.time-tracking.entries.approve');
        Route::post('/admin/time-tracking/entries/{timeEntry}/unapprove', [AdminTimeEntryReviewController::class, 'unapprove'])
            ->name('admin.time-tracking.entries.unapprove');
        Route::delete('/admin/time-tracking/entries/{timeEntry}', [AdminTimeEntryReviewController::class, 'destroy'])
            ->name('admin.time-tracking.entries.destroy');
        Route::get('/admin/projects', [AdminProjectController::class, 'index'])->name('admin.projects');
        Route::get('/admin/projects/{project}/workspace', [AdminProjectWorkspaceController::class, 'show'])
            ->name('admin.projects.workspace.show');
        Route::put('/admin/projects/{project}/workspace', [AdminProjectWorkspaceController::class, 'update'])
            ->name('admin.projects.workspace.update');
        Route::post('/admin/projects/{project}/attachments', [AdminProjectWorkspaceController::class, 'uploadAttachment'])
            ->name('admin.projects.attachments.store');
        Route::get('/admin/project-attachments/{attachment}', [AdminProjectWorkspaceController::class, 'attachment'])
            ->name('admin.projects.attachments.show');
        Route::get('/admin/jobs', [AdminJobController::class, 'index'])->name('admin.jobs');
        Route::get('/admin/jobs/{workOrder}/workspace', [AdminJobWorkspaceController::class, 'show'])
            ->name('admin.jobs.workspace.show');
        Route::put('/admin/jobs/{workOrder}/workspace', [AdminJobWorkspaceController::class, 'update'])
            ->name('admin.jobs.workspace.update');
        Route::post('/admin/jobs/{workOrder}/attachments', [AdminJobWorkspaceController::class, 'uploadAttachment'])
            ->name('admin.jobs.attachments.store');
        Route::get('/admin/job-attachments/{attachment}', [AdminJobWorkspaceController::class, 'attachment'])
            ->name('admin.jobs.attachments.show');
        Route::get('/admin/visits/{visit}/workspace', [AdminVisitWorkspaceController::class, 'show'])
            ->name('admin.visits.workspace.show');
        Route::put('/admin/visits/{visit}/workspace', [AdminVisitWorkspaceController::class, 'update'])
            ->name('admin.visits.workspace.update');
        Route::get('/admin/archive', [AdminArchiveController::class, 'page'])->name('admin.archive');
        Route::get('/admin/sync-logs', [AdminSyncLogController::class, 'index'])
            ->middleware('can:integrations.manage')
            ->name('admin.sync-logs');
        Route::post('/admin/sync-logs/sharepoint-pointers/retire', [AdminSyncLogController::class, 'retireSharePointPointers'])
            ->middleware('can:integrations.manage')
            ->middleware('throttle:5,1')
            ->name('admin.sync-logs.sharepoint-pointers.retire');
        Route::post('/admin/sync-logs/failed-jobs/{failedJobId}/forget', [AdminSyncLogController::class, 'forgetFailedQuickBooksCustomerSync'])
            ->whereNumber('failedJobId')
            ->middleware('can:integrations.manage')
            ->middleware('throttle:5,1')
            ->name('admin.sync-logs.failed-jobs.forget');
        Route::post('/admin/sync-logs/customers/{customer}/retry-create', [AdminSyncLogController::class, 'retryCustomerCreate'])
            ->middleware('can:integrations.manage')
            ->middleware('throttle:10,1')
            ->name('admin.sync-logs.customers.retry-create');
        Route::post('/admin/sync-logs/visits/{visit}/retry', [AdminSyncLogController::class, 'retryVisit'])
            ->middleware('can:integrations.manage')
            ->middleware('throttle:10,1')
            ->name('admin.sync-logs.visits.retry');
        Route::post('/admin/sync-logs/notifications/{outbox}/retry', [AdminSyncLogController::class, 'retryNotification'])
            ->middleware('can:integrations.manage')
            ->middleware('throttle:10,1')
            ->name('admin.sync-logs.notifications.retry');
        Route::post('/admin/sync-logs/outlook-contacts/{contactId}/resync', [AdminSyncLogController::class, 'resyncOutlookContact'])
            ->whereNumber('contactId')
            ->middleware('can:integrations.manage')
            ->middleware('throttle:10,1')
            ->name('admin.sync-logs.outlook-contacts.resync');
        Route::post('/admin/sync-logs/outlook-contacts/{contactId}/repair', [AdminSyncLogController::class, 'repairOutlookContact'])
            ->whereNumber('contactId')
            ->middleware('can:integrations.manage')
            ->middleware('throttle:10,1')
            ->name('admin.sync-logs.outlook-contacts.repair');
        Route::post('/admin/archive/{batch}/restore', [AdminArchiveController::class, 'restoreFromPage'])
            ->name('admin.archive.restore');
        Route::get('/admin/archives', [AdminArchiveController::class, 'index'])->name('admin.archives.index');
        Route::post('/admin/archives/batches/{batch}/restore', [AdminArchiveController::class, 'restore'])
            ->name('admin.archives.restore');
        Route::post('/admin/archives/{type}/{record}', [AdminArchiveController::class, 'archive'])
            ->name('admin.archives.archive');
        Route::get('/admin/users', [AdminUserController::class, 'index'])->middleware('can:users.manage')->name('admin.users');
        Route::get('/admin/onboarding/access', [AdminOnboardingAreaAccessController::class, 'show'])
            ->middleware('can:integrations.manage')
            ->name('admin.onboarding.access');
        Route::post('/admin/onboarding/access', [AdminOnboardingAreaAccessController::class, 'store'])
            ->middleware(['can:integrations.manage', 'throttle:5,1'])
            ->name('admin.onboarding.access.unlock');
        Route::middleware(RequireAdminOnboardingAreaAccess::class)->group(function (): void {
            Route::get('/admin/onboarding', [AdminOnboardingController::class, 'show'])
                ->middleware('can:users.manage')
                ->name('admin.onboarding');
            Route::put('/admin/onboarding/customer-import-mode', [AdminOnboardingController::class, 'setCustomerImportMode'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.customer-import-mode.update');
            Route::put('/admin/onboarding/quickbooks-opt-in', [AdminOnboardingController::class, 'setQuickBooksSyncOptIn'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.quickbooks-opt-in.update');
            Route::post('/admin/onboarding/bws/defer', [AdminOnboardingController::class, 'deferBwsRuntimeReader'])
                ->middleware(['can:users.manage', 'throttle:5,1'])
                ->name('admin.onboarding.bws.defer');
            Route::post('/admin/onboarding/bws/resume', [AdminOnboardingController::class, 'resumeBwsRuntimeReader'])
                ->middleware(['can:users.manage', 'throttle:5,1'])
                ->name('admin.onboarding.bws.resume');
            Route::post('/admin/onboarding/initial-import/customer-sites/preview', [AdminOnboardingController::class, 'previewCustomerSiteImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.customer-sites.preview');
            Route::post('/admin/onboarding/initial-import/customer-sites/start', [AdminOnboardingController::class, 'startCustomerSiteImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.customer-sites.start');
            Route::post('/admin/onboarding/initial-import/customer-sites/resume', [AdminOnboardingController::class, 'resumeCustomerSiteImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.customer-sites.resume');
            Route::post('/admin/onboarding/initial-import/customer-sites/csv/preview', [AdminOnboardingController::class, 'previewCsvCustomerSiteImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.customer-sites.csv.preview');
            Route::post('/admin/onboarding/initial-import/customer-sites/csv/start', [AdminOnboardingController::class, 'startCsvCustomerSiteImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.customer-sites.csv.start');
            Route::post('/admin/onboarding/initial-import/items/preview', [AdminOnboardingController::class, 'previewItemCatalogImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.items.preview');
            Route::post('/admin/onboarding/initial-import/items/start', [AdminOnboardingController::class, 'startItemCatalogImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.items.start');
            Route::post('/admin/onboarding/initial-import/items/resume', [AdminOnboardingController::class, 'resumeItemCatalogImport'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.items.resume');
            Route::post('/admin/onboarding/initial-import/resume', [AdminOnboardingController::class, 'resumeInitialImport'])
                ->middleware(['can:users.manage', 'throttle:5,1'])
                ->name('admin.onboarding.initial-import.resume');
            Route::post('/admin/onboarding/reconcile', [AdminOnboardingController::class, 'reconcileFailures'])
                ->middleware(['can:users.manage', 'throttle:5,1'])
                ->name('admin.onboarding.reconcile');
            Route::post('/admin/onboarding/complete', [AdminOnboardingController::class, 'complete'])
                ->middleware(['can:users.manage', 'throttle:5,1'])
                ->name('admin.onboarding.complete');
        });
        Route::get('/admin/customers', [AdminCustomerController::class, 'index'])->name('admin.customers');
        Route::get('/admin/customers/{customer}/workspace', [AdminCustomerWorkspaceController::class, 'show'])
            ->name('admin.customers.workspace.show');
        Route::put('/admin/customers/{customer}/workspace', [AdminCustomerWorkspaceController::class, 'update'])
            ->name('admin.customers.workspace.update');
        Route::post('/admin/customers/{customer}/quickbooks/resolve', [AdminCustomerWorkspaceController::class, 'resolveQuickBooksConflict'])
            ->name('admin.customers.quickbooks.resolve');
        Route::post('/admin/customers/{customer}/attachments', [AdminCustomerWorkspaceController::class, 'uploadAttachment'])
            ->name('admin.customers.attachments.store');
        Route::get('/admin/customer-attachments/{attachment}', [AdminCustomerWorkspaceController::class, 'attachment'])
            ->name('admin.customer-attachments.show');
        Route::post('/admin/users/invitations', [AdminUserController::class, 'store'])->middleware('can:users.manage')->name('admin.users.invitations.store');
        Route::patch('/admin/users/{user}/status', [AdminUserController::class, 'updateStatus'])->middleware('can:users.manage')->name('admin.users.status.update');
        Route::patch('/admin/users/{user}/roles', [AdminUserController::class, 'updateRoles'])->middleware('can:users.manage')->name('admin.users.roles.update');
        Route::middleware(RequireAdminOnboardingAreaAccess::class)->group(function (): void {
            Route::get('/admin/integrations/quickbooks', [QuickBooksIntegrationController::class, 'show'])
                ->middleware('can:integrations.manage')
                ->name('admin.integrations.quickbooks.show');
            Route::post('/admin/integrations/quickbooks/items/sync', [QuickBooksIntegrationController::class, 'syncItems'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.integrations.quickbooks.items.sync');
            Route::put('/admin/integrations/quickbooks/sync-control', [QuickBooksIntegrationController::class, 'updateSyncControl'])
                ->middleware(['can:integrations.manage', 'throttle:5,1'])
                ->name('admin.integrations.quickbooks.sync-control.update');
            Route::get('/admin/integrations/bitwarden', [BitwardenSecretManagerConnectionController::class, 'show'])
                ->middleware('can:integrations.manage')
                ->name('admin.integrations.bitwarden.show');
            Route::post('/admin/integrations/bitwarden', [BitwardenSecretManagerConnectionController::class, 'store'])
                ->middleware('can:integrations.manage')
                ->middleware('throttle:5,1')
                ->name('admin.integrations.bitwarden.store');
            Route::get('/admin/integrations/microsoft-graph/onboarding-targets', [MicrosoftGraphOnboardingTargetController::class, 'show'])
                ->middleware('can:integrations.manage')
                ->name('admin.integrations.microsoft-graph.onboarding-targets.show');
            Route::put('/admin/integrations/microsoft-graph/onboarding-targets', [MicrosoftGraphOnboardingTargetController::class, 'store'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-target-save'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/validate-read-access', [MicrosoftGraphOnboardingTargetController::class, 'validateReadAccess'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-read-check'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.validate-read-access');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/send-linkback', [MicrosoftGraphOnboardingTargetController::class, 'sendLinkback'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-linkback'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.send-linkback');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/validate-permission-boundary', [MicrosoftGraphOnboardingTargetController::class, 'validatePermissionBoundary'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-read-check'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.validate-permission-boundary');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/permission-verification', [MicrosoftGraphOnboardingTargetController::class, 'storePermissionVerification'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-attestation'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.permission-verification.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/calendar-canaries', [MicrosoftGraphOnboardingTargetController::class, 'createCalendarCanary'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-create'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/calendar-canaries/{canary}/cleanup', [MicrosoftGraphOnboardingTargetController::class, 'retryCalendarCanaryCleanup'])
                ->whereUuid('canary')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-maintenance'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.cleanup');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/calendar-canaries/{canary}/reconcile', [MicrosoftGraphOnboardingTargetController::class, 'manuallyReconcileCalendarCanary'])
                ->whereUuid('canary')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-maintenance'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.reconcile');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/contact-canaries', [MicrosoftGraphOnboardingTargetController::class, 'createContactsCanary'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-create'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/contact-canaries/{canary}/cleanup', [MicrosoftGraphOnboardingTargetController::class, 'retryContactsCanaryCleanup'])
                ->whereUuid('canary')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-maintenance'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.cleanup');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/contact-canaries/{canary}/reconcile', [MicrosoftGraphOnboardingTargetController::class, 'manuallyReconcileContactsCanary'])
                ->whereUuid('canary')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-maintenance'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.reconcile');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/sharepoint-canaries', [MicrosoftGraphOnboardingTargetController::class, 'createSharePointCanary'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-create'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/sharepoint-canaries/{canary}/cleanup', [MicrosoftGraphOnboardingTargetController::class, 'retrySharePointCanaryCleanup'])
                ->whereUuid('canary')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-maintenance'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.cleanup');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/sharepoint-canaries/{canary}/reconcile', [MicrosoftGraphOnboardingTargetController::class, 'manuallyReconcileSharePointCanary'])
                ->whereUuid('canary')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-canary-maintenance'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.reconcile');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/finalize-readiness', [MicrosoftGraphOnboardingTargetController::class, 'finalizeReadiness'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-finalize'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.finalize-readiness');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/activate-runtime-target', [MicrosoftGraphOnboardingTargetController::class, 'activateRuntimeTarget'])
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-finalize'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.activate-runtime-target');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/runtime-reconciliation/visits/{visit}', [MicrosoftGraphOnboardingTargetController::class, 'reconcileRuntimeVisit'])
                ->whereNumber('visit')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-attestation'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/runtime-reconciliation/visits/{visit}/confirm-owner', [MicrosoftGraphOnboardingTargetController::class, 'confirmRuntimeVisitOwnership'])
                ->whereNumber('visit')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-attestation'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.confirm-owner');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/runtime-reconciliation/active-visits/{visit}/reconcile', [MicrosoftGraphOnboardingTargetController::class, 'retireActiveRuntimeVisitEvent'])
                ->whereNumber('visit')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-attestation'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.active-visits.reconcile');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/runtime-reconciliation/contacts/{contact}', [MicrosoftGraphOnboardingTargetController::class, 'reconcileRuntimeContact'])
                ->whereNumber('contact')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-attestation'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.contacts.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/runtime-reconciliation/visits/{visit}', [MicrosoftGraphOnboardingTargetController::class, 'reconcileRuntimeVisit'])
                ->whereNumber('visit')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-attestation'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.store');
            Route::post('/admin/integrations/microsoft-graph/onboarding-targets/runtime-reconciliation/contacts/{contact}', [MicrosoftGraphOnboardingTargetController::class, 'reconcileRuntimeContact'])
                ->whereNumber('contact')
                ->middleware(['can:integrations.manage', 'throttle:graph-onboarding-attestation'])
                ->name('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.contacts.store');
            Route::get('/admin/integrations/quickbooks/redirect', [QuickBooksIntegrationController::class, 'redirect'])
                ->middleware('can:integrations.manage')
                ->middleware('throttle:10,1')
                ->name('admin.integrations.quickbooks.redirect');
        });
        Route::get('/admin/integrations/quickbooks/callback', [QuickBooksIntegrationController::class, 'callback'])
            ->middleware('can:integrations.manage')
            ->middleware('throttle:10,1')
            ->name('admin.integrations.quickbooks.callback');
    });
    Route::get('/time-entries/active', [TimeEntryTimerController::class, 'active'])
        ->middleware('can:work_orders.view')
        ->name('time-entries.active');
    Route::post('/time-entries/timer/start', [TimeEntryTimerController::class, 'start'])
        ->middleware('can:work_orders.manage')
        ->name('time-entries.timer.start');
    Route::post('/time-entries/{timeEntry}/stop', [TimeEntryTimerController::class, 'stop'])
        ->middleware('can:work_orders.manage')
        ->name('time-entries.timer.stop');
    Route::get('/calendar', [OrganizationCalendarController::class, 'index'])->name('calendar');
    Route::get('/search', [CustomerController::class, 'globalSearch'])->name('global.search');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::post('/customers/{customer}/quickbooks-refresh', [CustomerController::class, 'checkQuickBooksUpdates'])
        ->middleware('throttle:10,1')
        ->name('customers.quickbooks.refresh');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::get('/work-orders/{workOrder}/pdf', WorkOrderExportController::class)->name('work-orders.pdf');
    Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
    Route::put('/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');
    Route::patch('/work-orders/{workOrder}/status', [WorkOrderController::class, 'updateStatus'])->name('work-orders.status.update');
    Route::post('/work-orders/{workOrder}/attachments', [WorkOrderController::class, 'uploadAttachments'])->name('work-orders.attachments.store');
    Route::post('/work-orders/{workOrder}/revisits', [WorkOrderController::class, 'storeRevisit'])->name('work-orders.revisits.store');
    Route::put('/visits/{visit}/schedule', [WorkOrderController::class, 'rescheduleVisit'])->name('visits.schedule');
    Route::patch('/visits/{visit}/scope', [WorkOrderController::class, 'updateVisitScope'])->name('visits.scope.update');
    Route::get('/work-order-attachments/{attachment}', [WorkOrderController::class, 'attachment'])->name('work-order-attachments.download');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/trusted-devices/revoke', [TrustedDeviceController::class, 'destroyAll'])
        ->middleware('password.confirm')
        ->name('profile.trusted-devices.revoke');
    Route::post('/logout-all', LogoutAllDevicesController::class)
        ->middleware('throttle:5,1')
        ->name('logout.all');
});

require __DIR__.'/auth.php';
