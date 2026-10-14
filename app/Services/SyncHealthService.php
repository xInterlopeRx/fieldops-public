<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\GraphNotificationOutbox;
use App\Models\Site;
use App\Models\Visit;

class SyncHealthService
{
    private const OUTBOX_ERROR_STATUSES = [
        'failed',
        'unknown',
        'blocked_size',
        'blocked_attachment',
        'skipped_authorization',
    ];

    private const OUTBOX_PENDING_STATUSES = ['pending', 'sending'];

    public function __construct(
        private readonly QuickBooksApiClient $quickBooks,
        private readonly QuickBooksSyncControl $quickBooksSyncControl,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $quickBooksLinked = Customer::query()->whereNotNull('quickbooks_id')->exists();
        $quickBooksConnected = $quickBooksLinked && $this->quickBooks->isConnectedForCurrentEnvironment();
        $quickBooksFailed = Customer::query()->where('quickbooks_sync_status', 'failed')->count();
        $quickBooksPendingCustomers = Customer::query()->where('quickbooks_sync_status', 'pending')->count();
        $quickBooksPendingSites = Site::query()->whereNotNull('quickbooks_write_request_id')->count();
        $quickBooksSyncEnabled = $this->quickBooksSyncControl->isEnabled();

        $graphVisitFailed = Visit::query()->where('graph_sync_status', 'failed')->count();
        $graphVisitPending = Visit::query()->whereIn('graph_sync_status', ['pending', 'syncing'])->count();
        $graphVisitConfiguration = Visit::query()->where('graph_sync_status', 'pending_configuration')->count();

        $graphMailFailed = GraphNotificationOutbox::query()->whereIn('status', self::OUTBOX_ERROR_STATUSES)->count();
        $graphMailPending = GraphNotificationOutbox::query()->whereIn('status', self::OUTBOX_PENDING_STATUSES)->count();
        $graphMailConfiguration = GraphNotificationOutbox::query()->where('status', 'pending_configuration')->count();

        $outlookContactFailed = Contact::withArchived()->whereIn('outlook_sync_status', ['failed', 'unknown'])->count();
        $outlookContactPending = Contact::withArchived()->whereIn('outlook_sync_status', ['pending', 'syncing'])->count();

        $errors = $quickBooksFailed + $graphVisitFailed + $graphMailFailed + $outlookContactFailed;
        $pending = $quickBooksPendingCustomers + $quickBooksPendingSites + $graphVisitPending + $graphMailPending + $outlookContactPending;
        $configuration = $graphVisitConfiguration + $graphMailConfiguration;
        $graphWritesDisabled = ! config('services.microsoft_graph.calendar_writes_enabled')
            || ! config('services.microsoft_graph.writes_enabled')
            || ! config('services.microsoft_graph.contacts_sync_enabled');
        $quickBooksUnavailable = $quickBooksLinked && ! $quickBooksConnected;

        $status = $errors > 0
            ? 'error'
            : (($pending > 0 || $configuration > 0 || $graphWritesDisabled || $quickBooksUnavailable || ! $quickBooksSyncEnabled) ? 'warning' : 'healthy');
        $label = match ($status) {
            'error' => 'Sync issues',
            'warning' => 'Sync attention',
            default => 'Sync healthy',
        };

        $summaryParts = [];
        if ($errors > 0) {
            $summaryParts[] = $errors.' failed or blocked item'.($errors === 1 ? '' : 's');
        }
        if ($pending > 0) {
            $summaryParts[] = $pending.' pending item'.($pending === 1 ? '' : 's');
        }
        if ($configuration > 0 || $graphWritesDisabled) {
            $summaryParts[] = 'Microsoft Graph writes disabled or awaiting configuration';
        }
        if ($quickBooksUnavailable) {
            $summaryParts[] = 'QuickBooks is not connected for the configured environment';
        }
        if (! $quickBooksSyncEnabled) {
            $summaryParts[] = 'QuickBooks synchronization is paused by an Admin';
        }
        if ($summaryParts === []) {
            $summaryParts[] = 'No current synchronization issues';
        }

        return [
            'status' => $status,
            'label' => $label,
            'summary' => implode('. ', $summaryParts).'.',
            'errors' => $errors,
            'pending' => $pending,
            'configuration' => $configuration,
            'quickBooks' => [
                'linkedCustomers' => Customer::query()->whereNotNull('quickbooks_id')->count(),
                'failed' => $quickBooksFailed,
                'pending' => $quickBooksPendingCustomers + $quickBooksPendingSites,
                'connected' => ! $quickBooksLinked || $quickBooksConnected,
                'environment' => config('services.quickbooks.environment'),
                'syncEnabled' => $quickBooksSyncEnabled,
            ],
            'graph' => [
                'calendarEnabled' => (bool) config('services.microsoft_graph.calendar_writes_enabled'),
                'mailEnabled' => (bool) config('services.microsoft_graph.writes_enabled'),
                'contactsEnabled' => (bool) config('services.microsoft_graph.contacts_sync_enabled'),
                'failed' => $graphVisitFailed + $graphMailFailed + $outlookContactFailed,
                'pending' => $graphVisitPending + $graphMailPending + $outlookContactPending,
                'configuration' => $configuration,
                'outlookContacts' => [
                    'failed' => $outlookContactFailed,
                    'pending' => $outlookContactPending,
                ],
            ],
        ];
    }
}
