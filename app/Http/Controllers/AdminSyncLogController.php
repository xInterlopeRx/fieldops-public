<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgetFailedQuickBooksCustomerSyncRequest;
use App\Http\Requests\RetireSharePointAttachmentPointersRequest;
use App\Jobs\PushQuickBooksCustomer;
use App\Jobs\SendGraphNotification;
use App\Jobs\SyncVisitMicrosoftGraphEvent;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GraphNotificationOutbox;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\ActivityLogService;
use App\Services\ForgetFailedQuickBooksCustomerSync;
use App\Services\MicrosoftGraphRuntimeTargetResolver;
use App\Services\OutlookContactSyncCoordinator;
use App\Services\QueueWorkerStatus;
use App\Services\QuickBooksApiClient;
use App\Services\QuickBooksCustomerWriteService;
use App\Services\QuickBooksSyncControl;
use App\Services\RetireSharePointAttachmentPointers;
use App\Services\SyncHealthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminSyncLogController extends Controller
{
    private const OUTLOOK_CONTACT_TARGET_RECONCILED = 'outlook_contact_target_reconciled';

    public function __construct(private readonly MicrosoftGraphRuntimeTargetResolver $runtimeTarget) {}

    public function index(
        Request $request,
        SyncHealthService $health,
        QuickBooksApiClient $quickBooks,
        QueueWorkerStatus $queueWorkerStatus,
        QuickBooksSyncControl $quickBooksSyncControl,
    ): Response {
        $filters = $request->validate([
            'contact_q' => ['nullable', 'string', 'max:100'],
            'contact_status' => ['nullable', Rule::in(['action_needed', 'all', 'reconciled', ...OutlookContactSyncCoordinator::STATUSES])],
        ]);
        $contactSearch = trim($filters['contact_q'] ?? '');
        $contactStatus = $filters['contact_status'] ?? 'action_needed';
        $contactsSyncEnabled = (bool) config('services.microsoft_graph.contacts_sync_enabled');
        $notificationMailboxId = $this->runtimeTarget->notificationMailboxId() ?? '';

        $quickBooksSandboxWrites = $quickBooks->isSandboxWriteEnabled();
        $quickBooksRows = Customer::query()
            ->where(function (Builder $query): void {
                $query->whereIn('quickbooks_sync_status', ['pending', 'failed'])
                    ->orWhereNotNull('quickbooks_write_request_id')
                    ->orWhereHas('sites', fn (Builder $sites) => $sites->whereNotNull('quickbooks_write_request_id'));
            })
            ->with(['sites' => fn ($sites) => $sites
                ->whereNotNull('quickbooks_write_request_id')
                ->orderBy('name')
                ->limit(50)])
            ->orderByRaw("CASE WHEN quickbooks_sync_status = 'failed' THEN 0 WHEN quickbooks_sync_status = 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get([
                'id', 'client_id', 'name', 'company_name', 'quickbooks_id',
                'quickbooks_sync_status', 'quickbooks_sync_error', 'quickbooks_sync_requested_at',
                'quickbooks_last_synced_at', 'quickbooks_write_request_id', 'updated_at',
            ])
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'clientId' => $customer->client_id,
                'label' => $customer->company_name ?: $customer->name,
                'linked' => $customer->quickbooks_id !== null,
                'status' => $customer->quickbooks_sync_status ?? ($customer->quickbooks_write_request_id !== null ? 'pending' : 'idle'),
                'error' => $customer->quickbooks_sync_error,
                'requestedAt' => $customer->quickbooks_sync_requested_at?->toIso8601String(),
                'lastSyncedAt' => $customer->quickbooks_last_synced_at?->toIso8601String(),
                'writePending' => $customer->quickbooks_write_request_id !== null,
                'sites' => $customer->sites->map(fn (Site $site): array => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'writePending' => $site->quickbooks_write_request_id !== null,
                ])->values(),
                'canResolve' => $quickBooksSyncControl->isEnabled()
                    && $customer->quickbooks_id !== null
                    && $customer->quickbooks_sync_status === 'failed',
                'canRetryCreate' => $customer->quickbooks_id === null && $customer->quickbooks_sync_status === 'failed' && $quickBooksSandboxWrites,
            ])
            ->values();

        $visits = Visit::query()
            ->whereIn('graph_sync_status', ['pending', 'pending_configuration', 'syncing', 'failed'])
            ->with(['workOrder:id,site_id,job_number,title', 'workOrder.site:id,customer_id,name', 'workOrder.site.customer:id,name,company_name'])
            ->orderByRaw("CASE WHEN graph_sync_status = 'failed' THEN 0 WHEN graph_sync_status = 'pending' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'work_order_id', 'visit_type', 'scheduled_at', 'graph_sync_status', 'graph_sync_error', 'graph_synced_at', 'updated_at'])
            ->map(fn (Visit $visit): array => [
                'id' => $visit->id,
                'type' => $visit->visit_type,
                'jobId' => $visit->workOrder?->id,
                'jobNumber' => $visit->workOrder?->job_number,
                'jobTitle' => $visit->workOrder?->title,
                'customer' => $visit->workOrder?->site?->customer?->company_name ?: $visit->workOrder?->site?->customer?->name,
                'site' => $visit->workOrder?->site?->name,
                'scheduledAt' => $visit->scheduled_at?->toIso8601String(),
                'status' => $visit->graph_sync_status,
                'error' => $visit->graph_sync_error,
                'syncedAt' => $visit->graph_synced_at?->toIso8601String(),
                'canRetry' => $visit->graph_sync_status === 'failed' && (bool) config('services.microsoft_graph.calendar_writes_enabled'),
            ])
            ->values();

        $outlookContacts = Contact::withArchived()
            ->with([
                'customer:id,name,company_name',
                'site:id,name',
            ])
            ->when($contactSearch !== '', function (Builder $query) use ($contactSearch): void {
                $like = '%'.mb_strtolower($contactSearch).'%';

                $query->where(function (Builder $contacts) use ($like, $contactSearch): void {
                    foreach (['first_name', 'middle_name', 'last_name', 'email', 'phone'] as $column) {
                        $contacts->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
                    }

                    if (ctype_digit($contactSearch)) {
                        $contacts->orWhere('id', (int) $contactSearch);
                    }

                    $contacts
                        ->orWhereHas('customer', fn (Builder $customer) => $customer
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(company_name) LIKE ?', [$like]))
                        ->orWhereHas('site', fn (Builder $site) => $site
                            ->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            })
            ->when($contactStatus === 'action_needed', fn (Builder $query) => $query
                ->where(function (Builder $contacts): void {
                    $contacts->whereNull('outlook_sync_status')
                        ->orWhere('outlook_sync_status', '!=', 'synced');
                })
                ->where(function (Builder $contacts): void {
                    $contacts->whereNull('outlook_sync_error')
                        ->orWhere('outlook_sync_error', '!=', self::OUTLOOK_CONTACT_TARGET_RECONCILED);
                }))
            ->when($contactStatus === 'reconciled', fn (Builder $query) => $query->where(
                'outlook_sync_error',
                self::OUTLOOK_CONTACT_TARGET_RECONCILED,
            ))
            ->when(! in_array($contactStatus, ['action_needed', 'all', 'reconciled'], true), fn (Builder $query) => $query->where('outlook_sync_status', $contactStatus))
            ->orderByRaw("CASE outlook_sync_status WHEN 'failed' THEN 0 WHEN 'unknown' THEN 1 WHEN 'pending' THEN 2 WHEN 'syncing' THEN 3 WHEN 'paused' THEN 4 WHEN 'not_synced' THEN 5 ELSE 6 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Contact $contact): array => $this->presentOutlookContact(
                $contact,
                $contactsSyncEnabled,
                $notificationMailboxId,
            ));

        $outbox = GraphNotificationOutbox::query()
            ->whereIn('status', ['pending', 'pending_configuration', 'sending', 'failed', 'unknown', 'blocked_size', 'blocked_attachment', 'skipped_authorization'])
            ->with('workOrder:id,site_id,job_number,title')
            ->orderByRaw("CASE WHEN status IN ('failed', 'unknown', 'blocked_size', 'blocked_attachment', 'skipped_authorization') THEN 0 WHEN status IN ('pending', 'sending') THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'work_order_id', 'visit_id', 'type', 'recipient_role', 'status', 'attempts', 'available_at', 'attempted_at', 'sent_at', 'last_error', 'updated_at'])
            ->map(fn (GraphNotificationOutbox $item): array => [
                'id' => $item->id,
                'type' => $item->type,
                'jobId' => $item->workOrder?->id,
                'jobNumber' => $item->workOrder?->job_number,
                'jobTitle' => $item->workOrder?->title,
                'visitId' => $item->visit_id,
                'recipientRole' => $item->recipient_role ?? 'client',
                'status' => $item->status,
                'attempts' => $item->attempts,
                'lastError' => $item->last_error,
                'updatedAt' => $item->updated_at?->toIso8601String(),
                'canRetry' => $item->status === 'failed' && (bool) config('services.microsoft_graph.writes_enabled'),
            ])
            ->values();

        $projectType = (new Project)->getMorphClass();
        $workOrderType = (new WorkOrder)->getMorphClass();
        $visitType = (new Visit)->getMorphClass();
        $projectAttachmentType = (new ProjectAttachment)->getMorphClass();
        $workOrderAttachmentType = (new WorkOrderAttachment)->getMorphClass();

        $activities = AuditEvent::query()
            ->whereIn('subject_type', [
                $projectType,
                $workOrderType,
                $visitType,
                $projectAttachmentType,
                $workOrderAttachmentType,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'subject_type', 'subject_id', 'event', 'summary', 'created_at'])
            ->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'type' => match ($event->subject_type) {
                    $projectType => 'Project',
                    $workOrderType => 'Job',
                    $visitType => 'Visit',
                    $projectAttachmentType, $workOrderAttachmentType => 'Attachment',
                    default => 'Record',
                },
                'record' => $event->summary,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])
            ->values();

        $sharePointPointers = Schema::hasTable('sharepoint_attachments')
            ? SharePointAttachment::query()
                ->with([
                    'company:id,name,company_name',
                    'site:id,name',
                    'project:id,name,project_number',
                    'job:id,job_number,title',
                ])
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'company_id', 'site_id', 'project_id', 'job_id', 'filename', 'created_at'])
                ->map(fn (SharePointAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'customer' => $attachment->company?->company_name ?: $attachment->company?->name,
                    'site' => $attachment->site?->name,
                    'project' => $attachment->project?->project_number ?: $attachment->project?->name,
                    'job' => $attachment->job?->job_number ?: $attachment->job?->title,
                    'createdAt' => $attachment->created_at?->toIso8601String(),
                ])
                ->values()
                ->all()
            : [];

        return Inertia::render('Admin/SyncLogs', [
            'health' => $health->summary(),
            'quickBooksConnection' => [
                'environment' => config('services.quickbooks.environment'),
                'connected' => $quickBooks->isConnectedForCurrentEnvironment(),
                'sandboxWritesEnabled' => $quickBooksSandboxWrites,
                'syncEnabled' => $quickBooksSyncControl->isEnabled(),
            ],
            'graphWrites' => [
                'calendarEnabled' => (bool) config('services.microsoft_graph.calendar_writes_enabled'),
                'mailEnabled' => (bool) config('services.microsoft_graph.writes_enabled'),
                'contactsEnabled' => $contactsSyncEnabled,
            ],
            'quickBooks' => $quickBooksRows,
            'visits' => $visits,
            'notifications' => $outbox,
            'outlookContacts' => $outlookContacts,
            'outlookContactFilters' => [
                'q' => $contactSearch,
                'status' => $contactStatus,
                'statuses' => ['reconciled', ...OutlookContactSyncCoordinator::STATUSES],
            ],
            'activities' => $activities,
            'queueBacklog' => $this->queueBacklog($queueWorkerStatus, $quickBooksSyncControl),
            'sharePointPointers' => $sharePointPointers,
        ]);
    }

    public function retireSharePointPointers(
        RetireSharePointAttachmentPointersRequest $request,
        RetireSharePointAttachmentPointers $retirement,
    ): JsonResponse {
        $validated = $request->safe()->only([
            'attachment_ids',
            'sharepoint_admin_upn',
            'verification_reference',
        ]);

        $count = $retirement->retire(
            $request->user(),
            array_map('intval', $validated['attachment_ids']),
            $validated['sharepoint_admin_upn'],
            $validated['verification_reference'],
        );

        return response()->json([
            'status' => 'retired',
            'count' => $count,
            'message' => $count.' local SharePoint metadata pointer(s) retired. No SharePoint request was made and no remote file was deleted.',
        ]);
    }

    public function forgetFailedQuickBooksCustomerSync(
        ForgetFailedQuickBooksCustomerSyncRequest $request,
        int $failedJobId,
        ForgetFailedQuickBooksCustomerSync $forgetFailedJob,
    ): JsonResponse {
        $forgetFailedJob->forget($request->user(), $failedJobId);

        return response()->json([
            'status' => 'forgotten',
            'failed_job_id' => $failedJobId,
            'message' => 'The failed QuickBooks customer sync record was forgotten. It was not retried and no QuickBooks request was made.',
        ]);
    }

    public function resyncOutlookContact(
        Request $request,
        int $contactId,
        OutlookContactSyncCoordinator $sync,
        ActivityLogService $activityLog,
    ): JsonResponse {
        abort_unless(config('services.microsoft_graph.contacts_sync_enabled'), 409, 'Microsoft Graph Outlook Contact sync is disabled.');

        return DB::transaction(function () use ($request, $contactId, $sync, $activityLog): JsonResponse {
            $contact = Contact::withArchived()->lockForUpdate()->findOrFail($contactId);

            abort_unless($sync->queue((int) $contact->getKey()), 409, $this->outlookContactQueueConflict($contact));
            $activityLog->record($contact, 'outlook_contact_resync_requested', [], $request->user());

            return response()->json(['status' => 'pending', 'message' => 'Outlook Contact resynchronization queued.'], 202);
        });
    }

    public function repairOutlookContact(
        Request $request,
        int $contactId,
        OutlookContactSyncCoordinator $sync,
        ActivityLogService $activityLog,
    ): JsonResponse {
        abort_unless(config('services.microsoft_graph.contacts_sync_enabled'), 409, 'Microsoft Graph Outlook Contact sync is disabled.');
        $validated = $request->validate([
            'manual_reconciliation_confirmed' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $contactId, $sync, $activityLog, $validated): JsonResponse {
            $contact = Contact::withArchived()->lockForUpdate()->findOrFail($contactId);

            if (
                $contact->outlook_sync_status === 'unknown' &&
                (! is_string($contact->outlook_contact_id) || $contact->outlook_contact_id === '')
            ) {
                abort_unless(
                    (bool) ($validated['manual_reconciliation_confirmed'] ?? false),
                    409,
                    'Manually inspect the configured Outlook Contacts folder and confirm that any possible duplicate was reconciled before retrying this create.',
                );
                abort_unless(
                    $sync->queueAfterManualReconciliation((int) $contact->getKey(), confirmed: true),
                    409,
                    $this->outlookContactQueueConflict($contact),
                );
                $activityLog->record($contact, 'outlook_contact_manual_reconciliation_confirmed', [], $request->user());

                return response()->json([
                    'status' => 'pending',
                    'message' => 'Manual Outlook reconciliation confirmed; a new FieldOps Contact create was queued.',
                ], 202);
            }

            abort_if($contact->is_archived, 409, 'Archived Contacts cannot be replaced; resync to retry archive cleanup.');
            abort_unless($sync->queue((int) $contact->getKey(), repair: true), 409, $this->outlookContactQueueConflict($contact));
            $activityLog->record($contact, 'outlook_contact_repair_requested', [], $request->user());

            return response()->json([
                'status' => 'pending',
                'message' => 'The linked Outlook Contact will be deleted and recreated from FieldOps data.',
            ], 202);
        });
    }

    private function presentOutlookContact(Contact $contact, bool $syncEnabled, string $mailboxId): array
    {
        $contactName = trim(implode(' ', array_filter([
            $contact->first_name,
            $contact->middle_name,
            $contact->last_name,
        ])));
        $outlookContactId = $contact->outlook_contact_id;
        $linkedMailboxId = $contact->outlook_contact_mailbox_id;
        $hasOutlookContactId = is_string($outlookContactId) && $outlookContactId !== '';
        $mailboxMatches = $hasOutlookContactId
            && is_string($linkedMailboxId)
            && trim($linkedMailboxId) === $mailboxId;
        $unknownCreate = $contact->outlook_sync_status === 'unknown' && ! $hasOutlookContactId;
        $untrackedLink = $hasOutlookContactId && (
            ! is_string($linkedMailboxId)
            || trim($linkedMailboxId) === ''
            || (! $contact->is_archived && ! $mailboxMatches)
        );
        $repairInProgress = ! $hasOutlookContactId
            && is_string($linkedMailboxId)
            && trim($linkedMailboxId) === $mailboxId
            && in_array($contact->outlook_sync_status, ['pending', 'syncing', 'failed', 'paused'], true);
        $untrackedMailboxWithoutId = ! $hasOutlookContactId
            && $linkedMailboxId !== null
            && ! $repairInProgress;
        $syncInProgress = in_array($contact->outlook_sync_status, ['pending', 'syncing'], true);
        $manuallyReconciled = $contact->outlook_sync_error === self::OUTLOOK_CONTACT_TARGET_RECONCILED;
        $canResync = $syncEnabled
            && ! $syncInProgress
            && ($contact->is_archived
                ? ($hasOutlookContactId
                    ? is_string($linkedMailboxId) && trim($linkedMailboxId) !== ''
                    : $linkedMailboxId === null && ! $unknownCreate)
                : $mailboxId !== '' && ! $unknownCreate && ! $untrackedLink
                    && ($linkedMailboxId === null || $repairInProgress || $mailboxMatches));
        $canRepair = $syncEnabled
            && ! $contact->is_archived
            && $mailboxId !== ''
            && $mailboxMatches
            && ! in_array($contact->outlook_sync_status, ['pending', 'syncing'], true);
        $canConfirmManualReconciliation = $syncEnabled
            && ! $contact->is_archived
            && $unknownCreate
            && $mailboxId !== ''
            && ($linkedMailboxId === null || (is_string($linkedMailboxId) && trim($linkedMailboxId) === $mailboxId));
        $blockedReason = match (true) {
            $syncInProgress => 'A Contact sync is already queued or running. Wait for the background worker before requesting another resync.',
            $unknownCreate => 'The previous create outcome is unknown. Reconcile this Contact in Outlook manually before creating another copy.',
            $untrackedLink => 'The stored Outlook ID is missing its configured mailbox owner or belongs to another mailbox.',
            $untrackedMailboxWithoutId => 'The stored mailbox owner has no Outlook Contact ID. Manual reconciliation is required.',
            $manuallyReconciled && ! $syncEnabled => 'The old Outlook link was already reconciled and its local pointer cleared. No reconciliation action is pending; Contact sync remains disabled.',
            ! $syncEnabled => 'Outlook Contact sync is disabled.',
            $mailboxId === '' && ! $contact->is_archived => 'The notification mailbox is not configured.',
            default => null,
        };

        return [
            'id' => $contact->getKey(),
            'contact' => $contactName !== '' ? $contactName : 'Contact #'.$contact->getKey(),
            'customer' => $contact->customer?->company_name ?: $contact->customer?->name,
            'customerId' => $contact->customer_id,
            'site' => $contact->site?->name,
            'outlookContactId' => $outlookContactId,
            'status' => $manuallyReconciled ? 'reconciled' : $contact->outlook_sync_status,
            'lastSyncedAt' => $contact->outlook_last_synced_at?->toIso8601String(),
            'requestedAt' => $contact->outlook_sync_requested_at?->toIso8601String(),
            'error' => $contact->outlook_sync_error,
            'archived' => $contact->is_archived,
            'canResync' => $canResync,
            'canRepair' => $canRepair || $canConfirmManualReconciliation,
            'repairMode' => $canConfirmManualReconciliation ? 'manual_reconciliation' : ($canRepair ? 'replace_linked' : null),
            'blockedReason' => $blockedReason,
        ];
    }

    private function outlookContactQueueConflict(Contact $contact): string
    {
        if (! config('services.microsoft_graph.contacts_sync_enabled')) {
            return 'Microsoft Graph Outlook Contact sync is disabled.';
        }

        $mailboxId = $this->runtimeTarget->notificationMailboxId() ?? '';

        if (! $contact->is_archived && $mailboxId === '') {
            return 'The Outlook notification mailbox is not configured.';
        }

        if (
            $contact->outlook_sync_status === 'unknown' &&
            (! is_string($contact->outlook_contact_id) || $contact->outlook_contact_id === '')
        ) {
            return 'The previous Outlook create outcome is unknown. Reconcile the Outlook mailbox manually before retrying to avoid a duplicate.';
        }

        if (in_array($contact->outlook_sync_status, ['pending', 'syncing'], true)) {
            return 'This Contact sync is already queued or running. Wait for the background worker before requesting another resync.';
        }

        if (
            is_string($contact->outlook_contact_id) &&
            $contact->outlook_contact_id !== '' &&
            (! is_string($contact->outlook_contact_mailbox_id) || trim($contact->outlook_contact_mailbox_id) !== $mailboxId)
        ) {
            return 'The stored Outlook ID belongs to a different or untracked mailbox. Reconcile that mailbox before retrying.';
        }

        return 'This Contact cannot be queued for Outlook synchronization with its current ID/mailbox state.';
    }

    /** @return array<string, mixed> */
    private function queueBacklog(QueueWorkerStatus $workerStatus, QuickBooksSyncControl $quickBooksSyncControl): array
    {
        $driver = (string) config('queue.default');
        $connection = config('queue.connections.'.$driver, []);
        $queueName = is_string($connection['queue'] ?? null) ? $connection['queue'] : 'default';
        $databaseConnection = $connection['connection'] ?? config('database.default');
        $table = is_string($connection['table'] ?? null) ? $connection['table'] : 'jobs';
        $retryAfter = max(1, (int) ($connection['retry_after'] ?? 180));
        $metrics = $this->databaseQueueMetrics($driver, $databaseConnection, $table, $queueName, $retryAfter);
        $additionalQueues = collect([
            QuickBooksSyncControl::SYNC_QUEUE,
            QuickBooksSyncControl::CUSTOMER_IMPORT_QUEUE,
        ])
            ->reject(fn (string $additionalQueue): bool => $additionalQueue === $queueName)
            ->map(fn (string $additionalQueue): array => [
                'queue' => $additionalQueue,
                ...$this->databaseQueueMetrics($driver, $databaseConnection, $table, $additionalQueue, $retryAfter),
                'worker' => $workerStatus->status($driver, $additionalQueue),
            ])
            ->values()
            ->all();
        $failedJobs = $this->failedQueueJobsSummary();

        return [
            'driver' => $driver,
            'queue' => $queueName,
            ...$metrics,
            'worker' => $workerStatus->status($driver, $queueName),
            'additionalQueues' => $additionalQueues,
            'quickBooksSyncEnabled' => $quickBooksSyncControl->isEnabled(),
            'quickBooksPausedJobs' => $quickBooksSyncControl->heldJobsSummary(),
            'failedJobsCount' => $failedJobs['count'] ?? null,
            'failedJobsTruncated' => $failedJobs['truncated'] ?? false,
            'reviewItems' => $this->reviewQueueItems($driver, $databaseConnection, $table, $queueName),
            'failedJobReviewItems' => $this->failedQueueJobReviewItems(),
        ];
    }

    /** @return list<array{id: int|string, queue: string, type: string, attempts: int, createdAt: string|null, availableAt: string|null, reservedAt: string|null}> */
    private function reviewQueueItems(string $driver, ?string $databaseConnection, string $table, string $queueName): array
    {
        if ($driver !== 'database' || ! Schema::connection($databaseConnection)->hasTable($table)) {
            return [];
        }

        $queueNames = array_values(array_unique([
            $queueName,
            QuickBooksSyncControl::SYNC_QUEUE,
            QuickBooksSyncControl::CUSTOMER_IMPORT_QUEUE,
        ]));

        return DB::connection($databaseConnection)
            ->table($table)
            ->whereIn('queue', $queueNames)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'queue', 'payload', 'attempts', 'created_at', 'available_at', 'reserved_at'])
            ->map(fn (object $job): array => [
                'id' => $job->id,
                'queue' => $job->queue,
                'type' => $this->safeQueueJobType($job->payload),
                'state' => $job->reserved_at !== null
                    ? 'Reserved by a worker'
                    : ((int) $job->available_at > now()->timestamp ? 'Delayed' : 'Ready'),
                'attempts' => (int) $job->attempts,
                'createdAt' => is_numeric($job->created_at) ? now()->setTimestamp((int) $job->created_at)->toIso8601String() : null,
                'availableAt' => is_numeric($job->available_at) ? now()->setTimestamp((int) $job->available_at)->toIso8601String() : null,
                'reservedAt' => is_numeric($job->reserved_at) ? now()->setTimestamp((int) $job->reserved_at)->toIso8601String() : null,
            ])
            ->all();
    }

    /** @return list<array{id: int|string, queue: string|null, type: string, failedAt: string|null, canForget: bool}> */
    private function failedQueueJobReviewItems(): array
    {
        $failed = config('queue.failed', []);
        if (! in_array($failed['driver'] ?? null, ['database', 'database-uuids'], true)) {
            return [];
        }

        $databaseConnection = is_string($failed['database'] ?? null) ? $failed['database'] : config('database.default');
        $table = is_string($failed['table'] ?? null) ? $failed['table'] : 'failed_jobs';
        $schema = Schema::connection($databaseConnection);
        if (! $schema->hasTable($table) || ! $schema->hasColumns($table, ['id', 'queue', 'payload', 'failed_at'])) {
            return [];
        }

        return DB::connection($databaseConnection)
            ->table($table)
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'queue', 'payload', 'failed_at'])
            ->map(function (object $job): array {
                $type = $this->safeQueueJobType($job->payload);

                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'type' => $type,
                    'failedAt' => $job->failed_at === null ? null : (string) $job->failed_at,
                    'canForget' => $type === 'QuickBooks customer sync',
                ];
            })
            ->all();
    }

    private function safeQueueJobType(mixed $payload): string
    {
        if (! is_string($payload)) {
            return 'Other queued work';
        }

        $decoded = json_decode($payload, true);
        $displayName = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return match ($displayName) {
            'App\\Jobs\\SyncQuickBooksCustomer' => 'QuickBooks customer sync',
            'App\\Jobs\\PushQuickBooksCustomer' => 'QuickBooks customer write',
            'App\\Jobs\\SyncVisitMicrosoftGraphEvent' => 'Visit calendar sync',
            'App\\Jobs\\SendGraphNotification' => 'Graph notification',
            'App\\Jobs\\SyncOutlookContact' => 'Outlook Contact sync',
            'Illuminate\\Notifications\\SendQueuedNotifications' => 'Queued notification',
            default => 'Other queued work',
        };
    }

    /** @return array{monitoringSupported: bool, totalJobs: int|null, readyJobs: int|null, delayedOrReservedJobs: int|null, oldestReadyAt: string|null, oldestReadyAgeSeconds: int|null, workerAttentionRequired: bool} */
    private function databaseQueueMetrics(
        string $driver,
        ?string $databaseConnection,
        string $table,
        string $queueName,
        int $retryAfter,
    ): array {
        $unsupported = [
            'monitoringSupported' => false,
            'totalJobs' => null,
            'readyJobs' => null,
            'delayedOrReservedJobs' => null,
            'oldestReadyAt' => null,
            'oldestReadyAgeSeconds' => null,
            'workerAttentionRequired' => false,
        ];

        if ($driver !== 'database' || ! Schema::connection($databaseConnection)->hasTable($table)) {
            return $unsupported;
        }

        $database = DB::connection($databaseConnection);
        $now = now()->timestamp;
        $queueJobs = $database->table($table)->where('queue', $queueName);
        $totalJobs = (clone $queueJobs)->count();
        $readyJobsQuery = (clone $queueJobs)->where(function ($query) use ($now, $retryAfter): void {
            $query->where(function ($available) use ($now): void {
                $available->whereNull('reserved_at')
                    ->where('available_at', '<=', $now);
            })->orWhere(function ($expiredReservation) use ($now, $retryAfter): void {
                $expiredReservation->whereNotNull('reserved_at')
                    ->where('reserved_at', '<=', $now - $retryAfter);
            });
        });
        $readyJobs = (clone $readyJobsQuery)->count();
        $oldestCreatedAt = (clone $readyJobsQuery)->min('created_at');
        $oldestReadyAgeSeconds = is_numeric($oldestCreatedAt)
            ? max(0, $now - (int) $oldestCreatedAt)
            : null;

        return [
            'monitoringSupported' => true,
            'totalJobs' => $totalJobs,
            'readyJobs' => $readyJobs,
            'delayedOrReservedJobs' => max(0, $totalJobs - $readyJobs),
            'oldestReadyAt' => is_numeric($oldestCreatedAt)
                ? now()->setTimestamp((int) $oldestCreatedAt)->toIso8601String()
                : null,
            'oldestReadyAgeSeconds' => $oldestReadyAgeSeconds,
            'workerAttentionRequired' => $readyJobs > 0 && ($oldestReadyAgeSeconds ?? 0) >= 120,
        ];
    }

    /** @return array{count: int, truncated: bool}|null */
    private function failedQueueJobsSummary(): ?array
    {
        $failed = config('queue.failed', []);
        $driver = $failed['driver'] ?? null;

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return null;
        }

        $databaseConnection = is_string($failed['database'] ?? null)
            ? $failed['database']
            : config('database.default');
        $table = is_string($failed['table'] ?? null) ? $failed['table'] : 'failed_jobs';

        if (! Schema::connection($databaseConnection)->hasTable($table)) {
            return null;
        }

        $failedIds = DB::connection($databaseConnection)
            ->table($table)
            ->limit(101)
            ->pluck('id');

        return [
            'count' => min(100, $failedIds->count()),
            'truncated' => $failedIds->count() > 100,
        ];
    }

    public function retryCustomerCreate(
        Request $request,
        Customer $customer,
        QuickBooksCustomerWriteService $writes,
        ActivityLogService $activityLog,
        QuickBooksSyncControl $quickBooksSyncControl,
    ): JsonResponse {
        abort_if($customer->quickbooks_id !== null, 409, 'This Customer is already linked to QuickBooks.');
        abort_unless($customer->quickbooks_sync_status === 'failed', 409, 'Only a failed QuickBooks Customer create can be retried.');

        if (! $writes->sandboxWritesEnabled()) {
            return response()->json(['message' => 'Connect the configured QuickBooks sandbox before retrying this create.'], 409);
        }

        if ($customer->quickbooks_write_request_id !== null) {
            $requestId = $customer->quickbooks_write_request_id;
            DB::transaction(function () use ($customer, $requestId, $request, $activityLog): void {
                $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
                abort_unless(
                    $lockedCustomer->quickbooks_sync_status === 'failed' && $lockedCustomer->quickbooks_write_request_id === $requestId,
                    409,
                    'The QuickBooks Customer create is no longer awaiting retry.',
                );
                $lockedCustomer->forceFill([
                    'quickbooks_sync_status' => 'pending',
                    'quickbooks_sync_error' => null,
                ])->save();
                $activityLog->record($lockedCustomer, 'quickbooks_retry_requested', ['operation' => 'customer_create'], $request->user());
                PushQuickBooksCustomer::dispatch((int) $lockedCustomer->getKey(), $requestId)
                    ->onQueue($quickBooksSyncControl->queueForDispatch())
                    ->afterCommit();
            });

            return response()->json([
                'status' => 'pending',
                'message' => $quickBooksSyncControl->isEnabled()
                    ? 'The previous idempotent QuickBooks Customer create was requeued.'
                    : 'QuickBooks sync is paused. The Customer create is held until sync is re-enabled.',
            ], 202);
        }

        $status = $writes->queueCustomer($customer, createIfUnlinked: true);

        if (! in_array($status, ['pending', 'paused'], true)) {
            return response()->json([
                'status' => $status,
                'message' => $status === 'not_connected'
                    ? 'Connect the configured QuickBooks sandbox before retrying this create.'
                    : 'The QuickBooks create could not be queued.',
            ], 409);
        }

        $activityLog->record($customer, 'quickbooks_retry_requested', ['operation' => 'customer_create'], $request->user());

        return response()->json([
            'status' => 'pending',
            'message' => $quickBooksSyncControl->isEnabled()
                ? 'QuickBooks sandbox create queued.'
                : 'QuickBooks sync is paused. The Customer create is held until sync is re-enabled.',
        ], 202);
    }

    public function retryVisit(Request $request, Visit $visit, ActivityLogService $activityLog): JsonResponse
    {
        abort_unless(config('services.microsoft_graph.calendar_writes_enabled'), 409, 'Microsoft Graph calendar writes are disabled.');
        abort_unless($visit->graph_sync_status === 'failed', 409, 'Only failed Visit synchronizations can be retried.');

        DB::transaction(function () use ($request, $visit, $activityLog): void {
            $lockedVisit = Visit::query()->lockForUpdate()->findOrFail($visit->getKey());
            abort_unless($lockedVisit->graph_sync_status === 'failed', 409, 'This Visit synchronization is no longer failed.');
            $lockedVisit->update(['graph_sync_status' => 'pending', 'graph_sync_error' => null]);
            $activityLog->record($lockedVisit, 'graph_visit_retry_requested', [], $request->user());
            SyncVisitMicrosoftGraphEvent::dispatch((int) $lockedVisit->getKey())->afterCommit();
        });

        return response()->json(['status' => 'pending', 'message' => 'Visit synchronization retry queued.'], 202);
    }

    public function retryNotification(Request $request, GraphNotificationOutbox $outbox, ActivityLogService $activityLog): JsonResponse
    {
        abort_unless(config('services.microsoft_graph.writes_enabled'), 409, 'Microsoft Graph notification writes are disabled.');

        DB::transaction(function () use ($request, $outbox, $activityLog): void {
            $lockedOutbox = GraphNotificationOutbox::query()->lockForUpdate()->findOrFail($outbox->getKey());
            abort_unless($lockedOutbox->status === 'failed', 409, 'Only known failed notifications can be retried; ambiguous or blocked deliveries are not automatically resent.');
            $lockedOutbox->update([
                'status' => 'pending',
                'available_at' => now(),
                'last_error' => null,
            ]);
            $lockedOutbox->loadMissing('workOrder');
            abort_if($lockedOutbox->workOrder === null, 409, 'Archived or unavailable Job notifications cannot be retried.');
            $activityLog->record($lockedOutbox->workOrder, 'graph_notification_retry_requested', [
                'notification_type' => $lockedOutbox->type,
            ], $request->user());
            SendGraphNotification::dispatch((string) $lockedOutbox->getKey())->afterCommit();
        });

        return response()->json(['status' => 'pending', 'message' => 'Notification retry queued.'], 202);
    }
}
