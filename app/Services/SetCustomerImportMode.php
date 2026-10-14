<?php

namespace App\Services;

use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\InstallationClaim;
use App\Models\IntegrationConnection;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksItemCatalogSync;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetCustomerImportMode
{
    /** @return array{mode: string, hasCustomers: bool, canChange: bool, requiresApiTransitionConfirmation: bool} */
    public function snapshot(): array
    {
        $state = ApplicationSetupState::query()->whereKey(1)->firstOrFail();
        $hasImport = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', $state->quickbooks_import_mode)
            ->exists();
        $hasCustomers = Customer::query()->exists() || Site::query()->exists();
        $canSwitchCsvImportToApi = $this->canSwitchCsvImportToApi($state->quickbooks_import_mode);
        $canChangeBeforeImport = ! $hasImport && ! $hasCustomers;

        return [
            'mode' => (string) $state->quickbooks_import_mode,
            'hasCustomers' => $hasCustomers,
            'canChange' => $state->onboarding_required
                && ! $state->quickbooks_sync_opted_in
                && ($canChangeBeforeImport || $canSwitchCsvImportToApi),
            'requiresApiTransitionConfirmation' => $canSwitchCsvImportToApi && ($hasImport || $hasCustomers),
        ];
    }

    public function change(User $actor, string $mode, bool $confirmCsvApiTransition = false): void
    {
        if (! in_array($mode, [
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API,
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        ], true)) {
            throw ValidationException::withMessages(['import_mode' => 'Choose a supported Customer/Site import mode.']);
        }

        DB::transaction(function () use ($actor, $mode, $confirmCsvApiTransition): void {
            $state = ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $claim = InstallationClaim::query()->whereKey(1)->first();

            if (! $state->onboarding_required
                || $claim === null
                || (string) $claim->claimed_by_user_id !== (string) $actor->getKey()
                || $actor->status !== 'active'
                || ! $actor->hasVerifiedEmail()
                || ! $actor->hasEnabledTwoFactorAuthentication()
                || ! $actor->can('integrations.manage')) {
                abort(403);
            }

            if ($state->quickbooks_import_mode === $mode) {
                return;
            }

            if ($state->quickbooks_sync_opted_in) {
                throw ValidationException::withMessages([
                    'import_mode' => 'The Customer/Site source cannot change after QuickBooks synchronization is opted in. Defer QuickBooks before choosing a different local-only source.',
                ]);
            }

            $isCsvApiTransition = $state->quickbooks_import_mode === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV
                && $mode === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API;

            if ($isCsvApiTransition && ! $this->canSwitchCsvImportToApi($state->quickbooks_import_mode)) {
                throw ValidationException::withMessages([
                    'import_mode' => 'The completed CSV data cannot be switched to QuickBooks mode because non-CSV Customer/Site data or prior QuickBooks setup exists.',
                ]);
            }

            $hasImportOrRecords = QuickBooksInitialCustomerImport::query()
                ->where('environment', app()->environment())
                ->exists()
                || Customer::query()->exists()
                || Site::query()->exists();

            if ($isCsvApiTransition && $hasImportOrRecords && ! $confirmCsvApiTransition) {
                throw ValidationException::withMessages([
                    'confirm_api_transition' => 'Confirm that CSV-imported Customers/Sites stay local-only and are not matched by name to QuickBooks records.',
                ]);
            }

            if (! $isCsvApiTransition && $hasImportOrRecords) {
                throw ValidationException::withMessages([
                    'import_mode' => 'The import source is locked after Customer/Site records or an import run exist. Use the explicit local test-data reset before changing it.',
                ]);
            }

            $previousMode = $state->quickbooks_import_mode;
            $state->forceFill(['quickbooks_import_mode' => $mode])->save();
            ApplicationSetupStep::query()
                ->where('step_key', ApplicationSetupStep::QUICKBOOKS_OAUTH)
                ->update([
                    'status' => ApplicationSetupStep::STATUS_NOT_REQUIRED,
                    'attempt_count' => 0,
                    'last_attempted_at' => null,
                    'completed_at' => null,
                    'failure_code' => null,
                    'progress_current' => null,
                    'progress_total' => null,
                    'actor_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);
            ApplicationSetupStep::query()
                ->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)
                ->update([
                    'status' => $mode === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API
                        ? ApplicationSetupStep::STATUS_NOT_REQUIRED
                        : $this->csvImportStepStatus(),
                    'attempt_count' => 0,
                    'last_attempted_at' => null,
                    'completed_at' => null,
                    'failure_code' => null,
                    'progress_current' => null,
                    'progress_total' => null,
                    'actor_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $state->getMorphClass(),
                'subject_id' => $state->getKey(),
                'event' => 'customer_import_mode_selected',
                'summary' => $actor->name.' selected the Customer/Site initial-import mode.',
                'metadata' => [
                    'environment' => app()->environment(),
                    'import_mode' => $mode,
                    'previous_import_mode' => $previousMode,
                    'csv_records_preserved_local_only' => $isCsvApiTransition,
                ],
                'created_at' => now(),
            ]);
        });
    }

    private function canSwitchCsvImportToApi(string $currentMode): bool
    {
        if ($currentMode !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV) {
            return false;
        }

        $csvImport = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
            ->first();
        $hasQuickBooksData = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
            ->exists()
            || IntegrationConnection::query()->where('provider', 'quickbooks')->exists()
            || Customer::withArchived()->whereNotNull('quickbooks_id')->exists()
            || Site::withArchived()->whereNotNull('quickbooks_id')->exists()
            || QuickBooksItemCatalogSync::query()->where('environment', app()->environment())->exists();
        $hasNonCsvRecords = Customer::withArchived()
            ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD))
            ->exists()
            || Site::withArchived()
                ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD))
                ->exists();

        return ! $hasQuickBooksData
            && ! $hasNonCsvRecords
            && ($csvImport === null
                || ($csvImport->status === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE
                    && ! $csvImport->failures()->exists()));
    }

    private function csvImportStepStatus(): string
    {
        $status = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
            ->value('status');

        return match ($status) {
            QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE => ApplicationSetupStep::STATUS_PASSED,
            QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS => ApplicationSetupStep::STATUS_IN_PROGRESS,
            QuickBooksInitialCustomerImport::STATUS_FAILED => ApplicationSetupStep::STATUS_FAILED,
            default => ApplicationSetupStep::STATUS_PENDING,
        };
    }
}
