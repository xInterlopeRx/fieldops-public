<?php

namespace App\Http\Controllers;

use App\Services\FieldPulse\FieldPulseDocumentImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * FieldPulse uploader: imports "our FieldPulse document" (an XLSX workbook).
 * Admin-only, behind the onboarding area-access gate. No external provider is
 * contacted — only the uploaded document is read.
 */
class AdminFieldPulseImportController extends Controller
{
    public function preview(Request $request, FieldPulseDocumentImportService $import): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:20480'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $preview = $import->preview($request->user(), $request->file('document'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (RuntimeException $exception) {
            return to_route('admin.onboarding')->with('error', 'FieldPulse document preview could not be completed.');
        }

        return to_route('admin.onboarding')
            ->with('fieldpulseImportPreview', $preview)
            ->with('status', 'FieldPulse document preview complete. No records were stored.');
    }

    public function start(Request $request, FieldPulseDocumentImportService $import): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:20480'],
            'confirm_import' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $import->start($request->user(), $request->file('document'));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (RuntimeException $exception) {
            return to_route('admin.onboarding')->with('error', 'FieldPulse upload could not start.');
        }

        return to_route('admin.onboarding')
            ->with('status', 'The FieldPulse document upload has been queued.');
    }

    public function resume(Request $request, FieldPulseDocumentImportService $import): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        try {
            $import->resume($request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (RuntimeException $exception) {
            return to_route('admin.onboarding')->with('error', 'FieldPulse upload could not resume.');
        }

        return to_route('admin.onboarding')->with('status', 'The FieldPulse upload has been re-queued.');
    }
}
