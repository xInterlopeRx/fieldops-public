<?php

namespace App\Http\Controllers;

use App\Exceptions\QuickBooksApiException;
use App\Models\QuickBooksItemCatalogSync;
use App\Services\FieldPulse\FieldPulseDocumentImportService;
use App\Services\OnboardingReadinessService;
use App\Services\QuickBooks\QuickBooksXlsxCustomerImportService;
use App\Services\QuickBooksInitialCustomerImportService;
use App\Services\QuickBooksItemCatalogSyncService;
use App\Services\SetQuickBooksSyncOptIn;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminOnboardingController extends Controller
{
    public function show(
        OnboardingReadinessService $onboarding,
        QuickBooksItemCatalogSyncService $itemCatalog,
        FieldPulseDocumentImportService $fieldPulse,
        QuickBooksXlsxCustomerImportService $quickBooksFile,
    ): Response {
        return Inertia::render('Admin/Onboarding', [
            'readiness' => $onboarding->snapshot(),
            'status' => session('status'),
            'error' => session('error'),
            'qboImportPreview' => session('qboImportPreview'),
            'qboItemImportPreview' => session('qboItemImportPreview'),
            'fieldpulseImportPreview' => session('fieldpulseImportPreview'),
            'fieldpulseImport' => $fieldPulse->currentRunSummary(),
            'quickBooksFilePreview' => session('quickbooksFilePreview'),
            'quickBooksFileImport' => $quickBooksFile->currentRunSummary(),
        ]);
    }

    public function setQuickBooksSyncOptIn(Request $request, SetQuickBooksSyncOptIn $optIn): RedirectResponse
    {
        $data = $request->validate([
            'opted_in' => ['required', 'boolean'],
            'confirm_change' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $optIn->update($request->user(), (bool) $data['opted_in']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')->with(
            'status',
            (bool) $data['opted_in']
                ? 'QuickBooks was added to onboarding. Verify Intuit credentials in BWS and connect the company before enabling sync; complete the required imports before the readiness receipt.'
                : 'QuickBooks setup was deferred. Microsoft Graph onboarding remains required, and QuickBooks can be added later.',
        );
    }

    public function deferBwsRuntimeReader(Request $request, OnboardingReadinessService $onboarding): RedirectResponse
    {
        $request->validate([
            'confirm_defer' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        $onboarding->deferBwsRuntimeReader($request->user());

        return to_route('admin.onboarding')
            ->with('status', 'BWS setup is paused. Invitations and BWS-dependent integrations remain locked until the required secrets are verified.');
    }

    public function resumeBwsRuntimeReader(Request $request, OnboardingReadinessService $onboarding): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $onboarding->resumeBwsRuntimeReader($request->user());

        return to_route('admin.onboarding')->with('status', 'BWS setup resumed. Verify the runtime reader and required application secrets to continue.');
    }

    public function previewCustomerSiteImport(Request $request, QuickBooksInitialCustomerImportService $import): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        try {
            $preview = $import->preview($request->user());
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks preview could not be completed. Check the connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks preview could not reach Intuit. No local records were changed; retry when the connection is available.');
        }

        return to_route('admin.onboarding')
            ->with('qboImportPreview', $preview)
            ->with('status', 'QuickBooks Customer/Site counts were previewed. No FieldOps records were changed.');
    }

    public function startCustomerSiteImport(Request $request, QuickBooksInitialCustomerImportService $import): RedirectResponse
    {
        $request->validate([
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $run = $import->start($request->user());
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Customer/Site import could not start. Check the company connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Customer/Site import could not reach Intuit. No local records were changed; retry when the connection is available.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')->with(
            'status',
            $run->status === 'in_progress'
                ? 'The resumable QuickBooks Customer/Site import has been queued. Progress will refresh while it runs.'
                : 'The QuickBooks Customer/Site import is already in progress.',
        );
    }

    public function resumeCustomerSiteImport(
        Request $request,
        QuickBooksInitialCustomerImportService $apiImport,
    ): RedirectResponse {
        $request->validate([
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $apiImport->resume($request->user());
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Customer/Site import could not resume. Check the company connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Customer/Site import could not reach Intuit. No local records were changed; retry when the connection is available.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')->with('status', 'The Customer/Site import resumed from the saved QuickBooks company snapshot.');
    }

    public function previewItemCatalogImport(Request $request, QuickBooksItemCatalogSyncService $itemCatalog): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        try {
            $preview = $itemCatalog->preview($request->user());
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Item catalog preview could not be completed. Check the connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Item catalog preview could not reach Intuit. No local Item records were changed.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')
            ->with('qboItemImportPreview', $preview)
            ->with('status', 'QuickBooks Item count preview complete. No Item records were changed.');
    }

    public function startItemCatalogImport(Request $request, QuickBooksItemCatalogSyncService $itemCatalog): RedirectResponse
    {
        $request->validate([
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $run = $itemCatalog->start($request->user());
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Item catalog sync could not start. Check the connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Item catalog sync could not reach Intuit. No local Item records were changed.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')->with(
            'status',
            $run->status === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS
                ? 'QuickBooks Item catalog sync was queued.'
                : 'QuickBooks Item catalog sync is already in progress.',
        );
    }

    public function resumeItemCatalogImport(Request $request, QuickBooksItemCatalogSyncService $itemCatalog): RedirectResponse
    {
        $request->validate([
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $itemCatalog->resume($request->user());
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Item catalog sync could not resume. Check the connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Item catalog sync could not reach Intuit. No local Item records were changed.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')->with('status', 'QuickBooks Item catalog sync resumed from the saved QuickBooks company snapshot.');
    }

    public function previewQuickBooksFileImport(Request $request, QuickBooksXlsxCustomerImportService $import): RedirectResponse
    {
        $request->validate([
            'quickbooks_file' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:20480'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $preview = $import->preview($request->user(), $request->file('quickbooks_file'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')
            ->with('quickbooksFilePreview', $preview)
            ->with('status', 'QuickBooks file preview complete. No records were stored.');
    }

    public function startQuickBooksFileImport(Request $request, QuickBooksXlsxCustomerImportService $import): RedirectResponse
    {
        $request->validate([
            'quickbooks_file' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:20480'],
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $import->start($request->user(), $request->file('quickbooks_file'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')->with('status', 'The QuickBooks file import has been queued.');
    }

    public function reconcileFailures(Request $request, OnboardingReadinessService $onboarding): RedirectResponse
    {
        $reconciled = $onboarding->reconcileFailureQueues($request->user());

        return to_route('admin.onboarding')->with(
            $reconciled ? 'status' : 'error',
            $reconciled
                ? 'No failed or pending provider work remains.'
                : 'Outstanding failed or pending provider work remains; setup stays paused.',
        );
    }

    public function complete(Request $request, OnboardingReadinessService $onboarding): RedirectResponse
    {
        $request->validate([
            'confirm_complete' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        $onboarding->complete($request->user());

        return to_route('admin.users')->with('success', 'Setup readiness was recorded. Staff invitations are now unlocked.');
    }
}
