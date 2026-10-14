<?php

namespace App\Services;

use App\Models\ArchiveBatch;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerAttachment;
use App\Models\CustomerEmailAddress;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArchiveService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly OutlookContactSyncCoordinator $outlookSync,
    ) {}

    /** @var array<string, class-string<Model>> */
    private const TYPES = [
        'customers' => Customer::class,
        'customer-email-addresses' => CustomerEmailAddress::class,
        'customer-attachments' => CustomerAttachment::class,
        'sites' => Site::class,
        'projects' => Project::class,
        'work-orders' => WorkOrder::class,
        'visits' => Visit::class,
        'contacts' => Contact::class,
        'customer-addresses' => CustomerAddress::class,
        'project-attachments' => ProjectAttachment::class,
        'work-order-attachments' => WorkOrderAttachment::class,
    ];

    /** @return list<string> */
    public function types(): array
    {
        return array_keys(self::TYPES);
    }

    public function archive(string $type, int|string $recordId, ?User $actor, ?string $reason = null): ArchiveBatch
    {
        return DB::transaction(function () use ($type, $recordId, $actor, $reason): ArchiveBatch {
            $record = $this->queryForType($type)
                ->withArchived()
                ->lockForUpdate()
                ->findOrFail($recordId);

            abort_if($record->is_archived, 409, 'This record is already archived.');

            $archivedAt = now();
            $batch = new ArchiveBatch;
            $batch->forceFill([
                'id' => (string) Str::uuid(),
                'root_type' => $type,
                'root_id' => $record->getKey(),
                'archived_by_user_id' => $actor?->getKey(),
                'reason' => filled($reason) ? trim($reason) : null,
                'archived_at' => $archivedAt,
            ])->save();

            $visited = [];
            $this->archiveRecord($record, $batch, $archivedAt, $visited);
            $this->activityLog->record($record, 'archived', ['archive_batch_id' => $batch->getKey()], $actor);

            return $batch;
        });
    }

    public function restore(string $batchId, User $actor): ArchiveBatch
    {
        return DB::transaction(function () use ($batchId, $actor): ArchiveBatch {
            $batch = ArchiveBatch::query()->lockForUpdate()->findOrFail($batchId);

            abort_if($batch->restored_at !== null, 409, 'This archive batch has already been restored.');

            $this->assertRestorable($batch);

            if (config('services.microsoft_graph.contacts_sync_enabled')) {
                Contact::withArchived()
                    ->where('archive_batch_id', $batch->getKey())
                    ->where('is_archived', true)
                    ->orderBy('id')
                    ->chunkById(100, function ($contacts): void {
                        foreach ($contacts as $contact) {
                            $this->outlookSync->queue((int) $contact->getKey());
                        }
                    });
            }

            foreach (self::TYPES as $modelClass) {
                $modelClass::withArchived()
                    ->where('archive_batch_id', $batch->getKey())
                    ->where('is_archived', true)
                    ->update([
                        'is_archived' => false,
                        'archived_at' => null,
                        'archive_batch_id' => null,
                    ]);
            }

            $batch->forceFill([
                'restored_by_user_id' => $actor->getKey(),
                'restored_at' => now(),
            ])->save();

            $root = $this->queryForType($batch->root_type)
                ->withArchived()
                ->find($batch->root_id);

            if ($root instanceof Model) {
                $this->activityLog->record($root, 'restored', ['archive_batch_id' => $batch->getKey()], $actor);
            }

            return $batch->refresh();
        });
    }

    /** @param Collection<int, ArchiveBatch> $batches
     * @return Collection<int, array<string, mixed>>
     */
    public function presentBatches(Collection $batches): Collection
    {
        $recordsByType = [];
        $recordCounts = [];
        $batchIds = $batches->pluck('id')->all();

        foreach ($batches->groupBy('root_type') as $type => $typedBatches) {
            $modelClass = self::TYPES[$type] ?? null;

            if ($modelClass === null) {
                continue;
            }

            $recordsByType[$type] = $modelClass::withArchived()
                ->whereKey($typedBatches->pluck('root_id')->all())
                ->get()
                ->keyBy(fn (Model $record): string => (string) $record->getKey());
        }

        foreach (self::TYPES as $type => $modelClass) {
            $table = (new $modelClass)->getTable();
            $counts = DB::table($table)
                ->select('archive_batch_id')
                ->selectRaw('COUNT(*) AS records_count')
                ->whereIn('archive_batch_id', $batchIds)
                ->where('is_archived', true)
                ->groupBy('archive_batch_id')
                ->get();

            foreach ($counts as $count) {
                $recordCounts[$count->archive_batch_id][$type] = (int) $count->records_count;
            }
        }

        return $batches->map(function (ArchiveBatch $batch) use ($recordsByType, $recordCounts): array {
            $record = $recordsByType[$batch->root_type][(string) $batch->root_id] ?? null;
            $counts = $recordCounts[$batch->getKey()] ?? [];

            return [
                'id' => $batch->getKey(),
                'type' => $batch->root_type,
                'record' => [
                    'id' => $batch->root_id,
                    'label' => $record === null ? 'Record #'.$batch->root_id : $this->recordLabel($record),
                ],
                'archived_at' => $batch->archived_at?->toIso8601String(),
                'archived_by' => $batch->archivedBy?->name,
                'reason' => $batch->reason,
                'record_count' => array_sum($counts),
                'record_counts' => $counts,
            ];
        })->values();
    }

    /** @param array<string, true> $visited */
    private function archiveRecord(Model $record, ArchiveBatch $batch, mixed $archivedAt, array &$visited): void
    {
        $visitKey = $record::class.':'.$record->getKey();

        if (isset($visited[$visitKey]) || $record->is_archived) {
            return;
        }

        if ($record instanceof Visit) {
            $this->archiveVisitTree($record, $batch, $archivedAt, $visited);

            return;
        }

        $visited[$visitKey] = true;

        if ($record instanceof Customer) {
            $this->archiveCustomerDescendants($record, $batch, $archivedAt, $visited);
        } elseif ($record instanceof Site) {
            $this->archiveSiteDescendants($record, $batch, $archivedAt, $visited);
        } elseif ($record instanceof Project) {
            $this->archiveProjectDescendants($record, $batch, $archivedAt, $visited);
        } elseif ($record instanceof WorkOrder) {
            $this->archiveWorkOrderDescendants($record, $batch, $archivedAt, $visited);
        }

        $this->markArchived($record, $batch, $archivedAt);
    }

    /** @param array<string, true> $visited */
    private function archiveCustomerDescendants(Customer $customer, ArchiveBatch $batch, mixed $archivedAt, array &$visited): void
    {
        foreach (Site::query()->where('customer_id', $customer->getKey())->orderBy('id')->lockForUpdate()->get() as $site) {
            $this->archiveRecord($site, $batch, $archivedAt, $visited);
        }

        foreach (Contact::query()->where('customer_id', $customer->getKey())->orderBy('id')->lockForUpdate()->get() as $contact) {
            $this->archiveRecord($contact, $batch, $archivedAt, $visited);
        }

        foreach (CustomerAddress::query()->where('customer_id', $customer->getKey())->orderBy('id')->lockForUpdate()->get() as $address) {
            $this->archiveRecord($address, $batch, $archivedAt, $visited);
        }

        foreach (CustomerEmailAddress::query()->where('customer_id', $customer->getKey())->orderBy('id')->lockForUpdate()->get() as $emailAddress) {
            $this->archiveRecord($emailAddress, $batch, $archivedAt, $visited);
        }

        foreach (CustomerAttachment::query()->where('customer_id', $customer->getKey())->orderBy('id')->lockForUpdate()->get() as $attachment) {
            $this->archiveRecord($attachment, $batch, $archivedAt, $visited);
        }

        foreach (Project::query()->where('customer_id', $customer->getKey())->orderBy('id')->lockForUpdate()->get() as $project) {
            $this->archiveRecord($project, $batch, $archivedAt, $visited);
        }
    }

    /** @param array<string, true> $visited */
    private function archiveSiteDescendants(Site $site, ArchiveBatch $batch, mixed $archivedAt, array &$visited): void
    {
        foreach (WorkOrder::query()->where('site_id', $site->getKey())->orderBy('id')->lockForUpdate()->get() as $workOrder) {
            $this->archiveRecord($workOrder, $batch, $archivedAt, $visited);
        }

        foreach (Contact::query()->where('site_id', $site->getKey())->orderBy('id')->lockForUpdate()->get() as $contact) {
            $this->archiveRecord($contact, $batch, $archivedAt, $visited);
        }

        foreach (CustomerAddress::query()->where('source_site_id', $site->getKey())->orderBy('id')->lockForUpdate()->get() as $address) {
            $this->archiveRecord($address, $batch, $archivedAt, $visited);
        }

        $projectIds = DB::table('project_site')
            ->where('site_id', $site->getKey())
            ->orderBy('project_id')
            ->pluck('project_id');

        foreach (Project::query()->whereKey($projectIds)->orderBy('id')->lockForUpdate()->get() as $project) {
            $hasOtherActiveSite = DB::table('project_site')
                ->join('sites', 'sites.id', '=', 'project_site.site_id')
                ->where('project_site.project_id', $project->getKey())
                ->where('sites.id', '!=', $site->getKey())
                ->where('sites.is_archived', false)
                ->exists();

            $hasJobOnOtherActiveSite = DB::table('work_orders')
                ->join('sites', 'sites.id', '=', 'work_orders.site_id')
                ->where('work_orders.project_id', $project->getKey())
                ->where('work_orders.is_archived', false)
                ->where('sites.id', '!=', $site->getKey())
                ->where('sites.is_archived', false)
                ->exists();

            if (! $hasOtherActiveSite && ! $hasJobOnOtherActiveSite) {
                $this->archiveRecord($project, $batch, $archivedAt, $visited);
            }
        }
    }

    /** @param array<string, true> $visited */
    private function archiveProjectDescendants(Project $project, ArchiveBatch $batch, mixed $archivedAt, array &$visited): void
    {
        foreach (WorkOrder::query()->where('project_id', $project->getKey())->orderBy('id')->lockForUpdate()->get() as $workOrder) {
            $this->archiveRecord($workOrder, $batch, $archivedAt, $visited);
        }

        foreach (ProjectAttachment::query()->where('project_id', $project->getKey())->orderBy('id')->lockForUpdate()->get() as $attachment) {
            $this->archiveRecord($attachment, $batch, $archivedAt, $visited);
        }
    }

    /** @param array<string, true> $visited */
    private function archiveWorkOrderDescendants(WorkOrder $workOrder, ArchiveBatch $batch, mixed $archivedAt, array &$visited): void
    {
        foreach (Visit::query()->where('work_order_id', $workOrder->getKey())->orderBy('id')->lockForUpdate()->get() as $visit) {
            $this->archiveRecord($visit, $batch, $archivedAt, $visited);
        }

        foreach (WorkOrderAttachment::query()->where('work_order_id', $workOrder->getKey())->orderBy('id')->lockForUpdate()->get() as $attachment) {
            $this->archiveRecord($attachment, $batch, $archivedAt, $visited);
        }
    }

    /** @param array<string, true> $visited */
    private function archiveVisitTree(Visit $root, ArchiveBatch $batch, mixed $archivedAt, array &$visited): void
    {
        $pending = [$root];

        while ($pending !== []) {
            /** @var Visit $visit */
            $visit = array_shift($pending);
            $visitKey = $visit::class.':'.$visit->getKey();

            if (isset($visited[$visitKey]) || $visit->is_archived) {
                continue;
            }

            $visited[$visitKey] = true;
            $revisits = Visit::query()
                ->where('initial_visit_id', $visit->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->markArchived($visit, $batch, $archivedAt);

            foreach ($revisits as $revisit) {
                $pending[] = $revisit;
            }
        }
    }

    private function markArchived(Model $record, ArchiveBatch $batch, mixed $archivedAt): void
    {
        if (($record instanceof Customer || $record instanceof Site) && $record->quickbooks_write_request_id !== null) {
            abort(409, 'A pending QuickBooks write must finish before this record can be archived.');
        }

        $record->forceFill([
            'is_archived' => true,
            'archived_at' => $archivedAt,
            'archive_batch_id' => $batch->getKey(),
        ])->save();
    }

    private function assertRestorable(ArchiveBatch $batch): void
    {
        $defaultAddressConflict = DB::table('customer_addresses as restoring')
            ->join('customer_addresses as active', function ($join): void {
                $join->on('active.customer_id', '=', 'restoring.customer_id')
                    ->on('active.address_type', '=', 'restoring.address_type');
            })
            ->where('restoring.archive_batch_id', $batch->getKey())
            ->where('restoring.is_archived', true)
            ->where('restoring.is_default', true)
            ->where('active.is_archived', false)
            ->where('active.is_default', true)
            ->where(function ($query) use ($batch): void {
                $query->whereNull('active.archive_batch_id')
                    ->orWhere('active.archive_batch_id', '!=', $batch->getKey());
            })
            ->exists();

        abort_if($defaultAddressConflict, 409, 'A default address already exists; remove the active default before restoring this archive.');

        $contactConflict = DB::table('contacts as restoring')
            ->join('contacts as active', function ($join): void {
                $join->on('active.customer_id', '=', 'restoring.customer_id')
                    ->on('active.contact_type', '=', 'restoring.contact_type')
                    ->where(function ($query): void {
                        $query->whereColumn('active.site_id', 'restoring.site_id')
                            ->orWhere(function ($query): void {
                                $query->whereNull('active.site_id')
                                    ->whereNull('restoring.site_id');
                            });
                    });
            })
            ->where('restoring.archive_batch_id', $batch->getKey())
            ->where('restoring.is_archived', true)
            ->where('restoring.contact_type', '!=', 'other')
            ->where('active.is_archived', false)
            ->where(function ($query) use ($batch): void {
                $query->whereNull('active.archive_batch_id')
                    ->orWhere('active.archive_batch_id', '!=', $batch->getKey());
            })
            ->exists();

        abort_if($contactConflict, 409, 'A contact of the same type already exists; resolve it before restoring this archive.');
    }

    private function queryForType(string $type): mixed
    {
        $modelClass = self::TYPES[$type] ?? abort(404, 'Unsupported archive record type.');

        return $modelClass::query();
    }

    private function recordLabel(Model $record): string
    {
        return match (true) {
            $record instanceof Customer => $record->company_name ?: $record->name ?: 'Customer #'.$record->getKey(),
            $record instanceof CustomerEmailAddress => $record->email,
            $record instanceof CustomerAttachment => $record->original_name,
            $record instanceof Site => $record->name ?: 'Site #'.$record->getKey(),
            $record instanceof Project => $record->project_number
                ? $record->project_number.' — '.$record->name
                : $record->name,
            $record instanceof WorkOrder => $record->job_number
                ? $record->job_number.' — '.$record->title
                : $record->title,
            $record instanceof Visit => 'Visit '.($record->scheduled_at?->format('Y-m-d H:i') ?? '#'.$record->getKey()),
            $record instanceof Contact => trim(implode(' ', array_filter([$record->first_name, $record->last_name])))
                ?: ($record->email ?: 'Contact #'.$record->getKey()),
            $record instanceof CustomerAddress => trim(implode(', ', array_filter([$record->label, $record->city])))
                ?: ucfirst($record->address_type).' address #'.$record->getKey(),
            $record instanceof ProjectAttachment, $record instanceof WorkOrderAttachment => $record->original_name,
            default => 'Record #'.$record->getKey(),
        };
    }
}
