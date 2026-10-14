<?php

namespace App\Http\Controllers;

use App\Exceptions\QuickBooksApiException;
use App\Models\ApplicationSetupState;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksItemCatalogSync;
use App\Services\CsvCustomerSiteImportService;
use App\Services\OnboardingReadinessService;
use App\Services\QuickBooksInitialCustomerImportService;
use App\Services\QuickBooksItemCatalogSyncService;
use App\Services\SetCustomerImportMode;
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
        SetCustomerImportMode $importMode,
        QuickBooksItemCatalogSyncService $itemCatalog,
    ): Response {
        return Inertia::render('Admin/Onboarding', [
            'readiness' => $onboarding->snapshot(),
            'importMode' => $importMode->snapshot(),
            'status' => session('status'),
            'error' => session('error'),
            'qboImportPreview' => session('qboImportPreview'),
            'qboItemImportPreview' => session('qboItemImportPreview'),
            'csvImportPreview' => session('csvImportPreview'),
        ]);
    }

    public function setCustomerImportMode(Request $request, SetCustomerImportMode $importMode): RedirectResponse
    {
        $request->validate([
            'import_mode' => ['required', 'in:quickbooks_api,csv_upload'],
            'password' => ['required', 'current_password'],
            'confirm_api_transition' => ['sometimes', 'boolean'],
        ]);

        try {
            $importMode->change(
                $request->user(),
                $request->string('import_mode')->toString(),
                $request->boolean('confirm_api_transition'),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $status = $request->string('import_mode')->toString() === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API
            && $request->boolean('confirm_api_transition')
                ? 'QuickBooks API mode is selected. Existing CSV-imported Customers/Sites remain local-only and are not matched by name; the API import creates separate QBO-linked records.'
                : 'The first-run Customer/Site import source was updated. Existing import and QuickBooks write-back paths remain unchanged for already-imported records.';

        return to_route('admin.onboarding')->with('status', $status);
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
        CsvCustomerSiteImportService $csvImport,
    ): RedirectResponse {
        $request->validate([
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $source = ApplicationSetupState::query()->whereKey(1)->value('quickbooks_import_mode')
                === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV
                ? QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
                : QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API;
            $run = QuickBooksInitialCustomerImport::query()
                ->where('environment', app()->environment())
                ->where('source', $source)
                ->firstOrFail();

            if ($run->source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
                $csvImport->resume($request->user());
            } else {
                $apiImport->resume($request->user());
            }
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Customer/Site import could not resume. Check the company connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.onboarding')->with('error', 'QuickBooks Customer/Site import could not reach Intuit. No local records were changed; retry when the connection is available.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.onboarding')->with(
            'status',
            $run->source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
                ? 'The local-only Customer/Site CSV import resumed from its protected source payload.'
                : 'The Customer/Site import resumed from the saved QuickBooks company snapshot.',
        );
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

    public function previewCsvCustomerSiteImport(Request $request, CsvCustomerSiteImportService $csvImport): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:5120'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $preview = $csvImport->preview($request->user(), $request->file('file'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (\RuntimeException $exception) {
            return to_route('admin.onboarding')->with('error', 'CSV preview could not be completed ('.$exception->getMessage().').');
        }

        return to_route('admin.onboarding')
            ->with('csvImportPreview', $preview)
            ->with('status', 'CSV preview complete. No file contents or Customer records were stored.');
    }

    public function startCsvCustomerSiteImport(Request $request, CsvCustomerSiteImportService $csvImport): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:5120'],
            'preview_sha256' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $run = $csvImport->start(
                $request->user(),
                $request->file('file'),
                $request->string('preview_sha256')->toString(),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (\RuntimeException $exception) {
            return to_route('admin.onboarding')->with('error', 'CSV import could not start ('.$exception->getMessage().').');
        }

        return to_route('admin.onboarding')->with(
            'status',
            $run->status === 'in_progress'
                ? 'The local-only Customer/Site CSV import has been queued.'
                : 'The Customer/Site CSV import is already in progress.',
        );
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
