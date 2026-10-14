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

class SetQuickBooksSyncOptIn
{
    public function update(User $actor, bool $optedIn): void
    {
        DB::transaction(function () use ($actor, $optedIn): void {
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

            if ((bool) $state->quickbooks_sync_opted_in === $optedIn) {
                return;
            }

            if ($optedIn && $state->quickbooks_import_mode !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API) {
                throw ValidationException::withMessages([
                    'quickbooks_opt_in' => 'Select QuickBooks API as the Customer/Site source before opting into synchronization. A completed CSV import can switch sources with confirmation; its existing rows remain local-only.',
                ]);
            }

            if (! $optedIn && ($state->quickbooks_sync_enabled || $this->hasQuickBooksSetupWork())) {
                throw ValidationException::withMessages([
                    'quickbooks_opt_in' => 'QuickBooks setup has already started. Pause synchronization instead; existing QuickBooks-linked records and setup history will be preserved.',
                ]);
            }

            $state->forceFill([
                'quickbooks_sync_opted_in' => $optedIn,
                'quickbooks_sync_opted_in_at' => $optedIn ? now() : null,
                'quickbooks_sync_verification_required' => $optedIn,
            ])->save();

            foreach ([ApplicationSetupStep::QUICKBOOKS_OAUTH, ApplicationSetupStep::INITIAL_QBO_IMPORT] as $stepKey) {
                ApplicationSetupStep::query()->where('step_key', $stepKey)->update([
                    'status' => $optedIn
                        ? ApplicationSetupStep::STATUS_PENDING
                        : ApplicationSetupStep::STATUS_NOT_REQUIRED,
                    'completed_at' => null,
                    'failure_code' => null,
                    'progress_current' => null,
                    'progress_total' => null,
                    'actor_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);
            }

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $state->getMorphClass(),
                'subject_id' => $state->getKey(),
                'event' => $optedIn ? 'quickbooks_onboarding_opted_in' : 'quickbooks_onboarding_deferred',
                'summary' => $actor->name.($optedIn
                    ? ' opted into QuickBooks synchronization during onboarding.'
                    : ' deferred QuickBooks synchronization during onboarding.'),
                'metadata' => [
                    'environment' => app()->environment(),
                    'quickbooks_sync_opted_in' => $optedIn,
                ],
                'created_at' => now(),
            ]);
        });
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
}
