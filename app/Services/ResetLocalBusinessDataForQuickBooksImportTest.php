<?php

namespace App\Services;

use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\Contact;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksInitialCustomerImportFailure;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ResetLocalBusinessDataForQuickBooksImportTest
{
    /** @var list<string> */
    private const BUSINESS_TABLES = [
        'visit_user',
        'timeline_events',
        'job_items',
        'quickbooks_item_catalog_syncs',
        'work_order_user',
        'project_site',
        'project_user',
        'customer_user',
        'contact_work_order',
        'time_entries',
        'visits',
        'work_order_attachments',
        'project_attachments',
        'customer_attachments',
        'sharepoint_attachments',
        'work_orders',
        'projects',
        'contact_methods',
        'contacts',
        'customer_addresses',
        'customer_email_addresses',
        'sites',
        'customers',
        'items',
        'notifications',
        'archive_batches',
    ];

    /** @var list<string> */
    private const ATTACHMENT_TABLES = [
        'customer_attachments',
        'project_attachments',
        'work_order_attachments',
        'sharepoint_attachments',
    ];

    /** @return array{environment: string, database: string, quickbooksEnvironment: string, adminAccountsPreserved: int, usersPreserved: int, integrationConnectionsPreserved: int, tables: array<string, int>} */
    public function preview(): array
    {
        [$admin, $counts] = $this->assertSafeTarget();

        return [
            'environment' => app()->environment(),
            'database' => (string) config('database.connections.pgsql.database'),
            'quickbooksEnvironment' => (string) config('services.quickbooks.environment'),
            'adminAccountsPreserved' => 1,
            'usersPreserved' => User::query()->count(),
            'integrationConnectionsPreserved' => DB::table('integration_connections')->count(),
            'tables' => $counts,
        ];
    }

    /** @return array{deleted: array<string, int>, adminUserId: int} */
    public function reset(): array
    {
        return DB::transaction(function (): array {
            ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            [$admin, $counts] = $this->assertSafeTarget();

            foreach (self::BUSINESS_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            if (Schema::hasTable('quickbooks_initial_customer_imports')) {
                QuickBooksInitialCustomerImportFailure::query()->delete();
                QuickBooksInitialCustomerImport::query()->delete();
            }

            if (Schema::hasTable('fieldpulse_import_failures')) {
                DB::table('fieldpulse_import_failures')->delete();
                DB::table('fieldpulse_import_runs')->delete();
            }

            InstallationClaim::query()->whereKey(1)->update([
                'claimed_at' => InstallationClaim::query()->whereKey(1)->value('claimed_at') ?? now(),
                'claimed_by_user_id' => $admin->getKey(),
                'updated_at' => now(),
            ]);

            $now = now();
            ApplicationSetupState::query()->whereKey(1)->update([
                'onboarding_required' => true,
                'quickbooks_sync_opted_in' => false,
                'quickbooks_sync_opted_in_at' => null,
                'quickbooks_sync_verification_required' => false,
                'quickbooks_sync_enabled' => false,
                'readiness_completed_at' => null,
                'readiness_completed_by_user_id' => null,
                'readiness_receipt_type' => null,
                'updated_at' => $now,
            ]);

            $steps = [
                ApplicationSetupStep::FIRST_ADMIN => ApplicationSetupStep::STATUS_PASSED,
                ApplicationSetupStep::BWS_RUNTIME_READER => ApplicationSetupStep::STATUS_PASSED,
                ApplicationSetupStep::QUICKBOOKS_OAUTH => ApplicationSetupStep::STATUS_NOT_REQUIRED,
                ApplicationSetupStep::INITIAL_QBO_IMPORT => ApplicationSetupStep::STATUS_PENDING,
                ApplicationSetupStep::GRAPH_VALIDATION => ApplicationSetupStep::STATUS_PENDING,
                ApplicationSetupStep::FAILURE_RECONCILIATION => ApplicationSetupStep::STATUS_PENDING,
            ];

            foreach ($steps as $stepKey => $status) {
                ApplicationSetupStep::query()->where('step_key', $stepKey)->update([
                    'status' => $status,
                    'attempt_count' => $status === ApplicationSetupStep::STATUS_PASSED ? 1 : 0,
                    'last_attempted_at' => $status === ApplicationSetupStep::STATUS_PASSED ? $now : null,
                    'completed_at' => $status === ApplicationSetupStep::STATUS_PASSED ? $now : null,
                    'failure_code' => null,
                    'progress_current' => null,
                    'progress_total' => null,
                    'actor_user_id' => $status === ApplicationSetupStep::STATUS_PASSED ? $admin->getKey() : null,
                    'updated_at' => $now,
                ]);
            }

            return [
                'deleted' => $counts,
                'adminUserId' => (int) $admin->getKey(),
            ];
        });
    }

    /** @return array{0: User, 1: array<string, int>} */
    private function assertSafeTarget(): array
    {
        if (! app()->environment('local') || config('database.default') !== 'pgsql') {
            throw new RuntimeException('This reset is restricted to the local PostgreSQL environment.');
        }

        $bws = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        if (! ($bws?->isVerifiedForCurrentEnvironment() ?? false)) {
            throw new RuntimeException('A verified BWS runtime connection is required.');
        }

        $admins = User::query()
            ->role('admin')
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $user): bool => $user->hasVerifiedEmail() && $user->hasEnabledTwoFactorAuthentication())
            ->values();

        if ($admins->count() !== 1) {
            throw new RuntimeException('The reset requires exactly one active, email-verified, TOTP-enabled Admin so the first-run claim is unambiguous.');
        }

        if (User::query()->whereNotNull('customer_id')->exists()) {
            throw new RuntimeException('The reset stopped because a staff account is linked to a Customer. Preserve that relationship and resolve it before resetting business data.');
        }

        if (DB::table('jobs')->exists() || DB::table('failed_jobs')->exists()) {
            throw new RuntimeException('The reset stopped because queued or failed jobs exist. Review and reconcile those jobs before resetting their business records.');
        }

        if (Schema::hasTable('graph_notification_outbox') && DB::table('graph_notification_outbox')->exists()) {
            throw new RuntimeException('The reset stopped because Graph notification outbox rows exist. Reconcile them before resetting their related records.');
        }

        $visitEventPointers = Visit::withArchived()
            ->whereNotNull('microsoft_graph_event_id')
            ->count();
        $unknownVisitCreates = Visit::withArchived()
            ->whereNull('microsoft_graph_event_id')
            ->whereNotNull('microsoft_graph_transaction_id')
            ->where('graph_sync_status', 'failed')
            ->count();
        $contactPointers = Contact::withArchived()->whereNotNull('outlook_contact_id')->count();
        $unknownContactCreates = Contact::withArchived()
            ->where('outlook_sync_status', 'unknown')
            ->whereNull('outlook_contact_id')
            ->count();

        if ($visitEventPointers + $unknownVisitCreates + $contactPointers + $unknownContactCreates > 0) {
            throw new RuntimeException('The reset stopped because mailbox-scoped Visit or Contact work remains. Have the M365 administrator verify and reconcile each remote item in Admin > Integrations > Microsoft Graph before clearing its local record. This reset never deletes Microsoft Graph items.');
        }

        $attachmentCounts = [];

        foreach (self::ATTACHMENT_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $attachmentCounts[$table] = DB::table($table)->count();
            }
        }

        if (array_sum($attachmentCounts) > 0) {
            throw new RuntimeException('The reset stopped because attachment metadata exists. This operation does not delete local files or SharePoint items.');
        }

        if (! Schema::hasTable('quickbooks_initial_customer_imports')
            || ! Schema::hasTable('quickbooks_initial_customer_import_failures')) {
            throw new RuntimeException('Run the additive QuickBooks initial-import migration before preparing this database.');
        }

        $counts = [];

        foreach (self::BUSINESS_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        $counts['quickbooks_initial_customer_import_failures'] = QuickBooksInitialCustomerImportFailure::query()->count();
        $counts['quickbooks_initial_customer_imports'] = QuickBooksInitialCustomerImport::query()->count();

        return [$admins->sole(), $counts];
    }
}
