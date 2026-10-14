<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\IntegrationConnection;
use App\Models\Item;
use App\Models\JobItem;
use App\Models\QuickBooksItemCatalogSync;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitJobItemService
{
    private const MAX_ITEMS_PER_VISIT = 100;

    private const MAX_ITEMS_PER_JOB = 1_000;

    public function __construct(
        private readonly QuickBooksApiClient $quickBooks,
        private readonly QuickBooksOAuthService $oauth,
    ) {}

    /** @return list<array{id: int, name: string, description: ?string, type: string}> */
    public function searchCatalog(Visit $visit, User $actor, string $search): array
    {
        $this->assertVisitAccess($visit, $actor, manage: false);
        $this->assertJobOpen($visit);
        $realmId = $this->currentRealmId();
        $search = trim($search);

        return Item::query()
            ->where('quickbooks_realm_id', $realmId)
            ->where('active', true)
            ->whereIn('type', Item::SELECTABLE_TYPES)
            ->when($search !== '', function ($query) use ($search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $query->where(function ($nested) use ($needle): void {
                    $nested->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(COALESCE(description, ?)) LIKE ?', ['', $needle]);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'description', 'type'])
            ->map(fn (Item $item): array => [
                'id' => (int) $item->getKey(),
                'name' => $item->name,
                'description' => $item->description,
                'type' => $item->type,
            ])
            ->all();
    }

    public function catalogReady(): bool
    {
        try {
            $this->currentRealmId();

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function canEditItems(User $actor, bool $catalogReady, bool $jobOpen, bool $assignedToJobOrVisit): bool
    {
        return $catalogReady
            && $jobOpen
            && $actor->can('work_orders.manage')
            && ($actor->can('admin.access') || $assignedToJobOrVisit);
    }

    /** @return array{id: int, itemId: int, name: string, type: string, quantity: string} */
    public function add(Visit $visit, User $actor, int $itemId, string $quantity): array
    {
        return DB::transaction(function () use ($visit, $actor, $itemId, $quantity): array {
            $lockedVisit = Visit::query()->lockForUpdate()->findOrFail($visit->getKey());
            $this->assertVisitAccess($lockedVisit, $actor, manage: true);
            $this->assertJobOpen($lockedVisit, lock: true);
            $item = $this->selectableItem($itemId);
            $jobItem = JobItem::query()
                ->where('visit_id', $lockedVisit->getKey())
                ->where('item_id', $item->getKey())
                ->lockForUpdate()
                ->first();

            if ($jobItem === null
                && JobItem::query()->where('visit_id', $lockedVisit->getKey())->count() >= self::MAX_ITEMS_PER_VISIT) {
                throw ValidationException::withMessages([
                    'item_id' => 'This Visit already has the maximum of '.self::MAX_ITEMS_PER_VISIT.' distinct Items.',
                ]);
            }

            if ($jobItem === null
                && JobItem::query()
                    ->whereIn('visit_id', Visit::query()->where('work_order_id', $lockedVisit->work_order_id)->select('id'))
                    ->count() >= self::MAX_ITEMS_PER_JOB) {
                throw ValidationException::withMessages([
                    'item_id' => 'This Job already has the maximum of '.self::MAX_ITEMS_PER_JOB.' distinct Visit Item records.',
                ]);
            }

            $jobItem ??= new JobItem([
                'visit_id' => $lockedVisit->getKey(),
                'item_id' => $item->getKey(),
                'quickbooks_item_id' => $item->quickbooks_id,
            ]);
            $isNew = ! $jobItem->exists;
            $jobItem->fill([
                'quickbooks_item_id' => $item->quickbooks_id,
                'item_name_snapshot' => $item->name,
                'item_description_snapshot' => $item->description,
                'quantity' => $quantity,
                'added_by_user_id' => $actor->getKey(),
            ])->save();

            $this->audit($actor, $lockedVisit, $jobItem, $isNew ? 'visit_item_added' : 'visit_item_quantity_updated');

            return $this->present($jobItem, $item);
        });
    }

    /** @return array{id: int, itemId: int, name: string, description: ?string, type: string, quantity: string} */
    public function updateQuantity(Visit $visit, JobItem $jobItem, User $actor, string $quantity): array
    {
        return DB::transaction(function () use ($visit, $jobItem, $actor, $quantity): array {
            $lockedVisit = Visit::query()->lockForUpdate()->findOrFail($visit->getKey());
            $this->assertVisitAccess($lockedVisit, $actor, manage: true);
            $this->assertJobOpen($lockedVisit, lock: true);
            $lockedItem = JobItem::query()
                ->where('visit_id', $lockedVisit->getKey())
                ->lockForUpdate()
                ->findOrFail($jobItem->getKey());

            $lockedItem->forceFill([
                'quantity' => $quantity,
            ])->save();
            $this->audit($actor, $lockedVisit, $lockedItem, 'visit_item_quantity_updated');

            return $this->present($lockedItem, $lockedItem->item()->firstOrFail());
        });
    }

    public function remove(Visit $visit, JobItem $jobItem, User $actor): void
    {
        DB::transaction(function () use ($visit, $jobItem, $actor): void {
            $lockedVisit = Visit::query()->lockForUpdate()->findOrFail($visit->getKey());
            $this->assertVisitAccess($lockedVisit, $actor, manage: true);
            $this->assertJobOpen($lockedVisit, lock: true);
            $lockedItem = JobItem::query()
                ->where('visit_id', $lockedVisit->getKey())
                ->lockForUpdate()
                ->findOrFail($jobItem->getKey());

            $this->audit($actor, $lockedVisit, $lockedItem, 'visit_item_removed');
            $lockedItem->delete();
        });
    }

    private function currentRealmId(): string
    {
        if (! $this->oauth->apiModeEnabled() || ! $this->quickBooks->isConnectedForCurrentEnvironment()) {
            throw ValidationException::withMessages([
                'item_id' => 'Connect the QuickBooks API before selecting Items for a Visit.',
            ]);
        }

        $realmId = IntegrationConnection::query()
            ->where('provider', 'quickbooks')
            ->where('environment', $this->oauth->environment())
            ->value('realm_id');

        if (! is_string($realmId) || $realmId === '') {
            throw ValidationException::withMessages(['item_id' => 'The QuickBooks company connection is unavailable.']);
        }

        if (! QuickBooksItemCatalogSync::query()
            ->where('environment', app()->environment())
            ->where('realm_id', $realmId)
            ->whereNotNull('completed_at')
            ->exists()) {
            throw ValidationException::withMessages([
                'item_id' => 'The QuickBooks Item catalog must finish syncing before Visit Items can be selected.',
            ]);
        }

        return $realmId;
    }

    private function selectableItem(int $itemId): Item
    {
        $realmId = $this->currentRealmId();
        $item = Item::query()
            ->where('quickbooks_realm_id', $realmId)
            ->where('active', true)
            ->whereIn('type', Item::SELECTABLE_TYPES)
            ->find($itemId);

        if ($item === null) {
            throw ValidationException::withMessages([
                'item_id' => 'Choose an active QuickBooks Item from the connected company.',
            ]);
        }

        return $item;
    }

    private function assertJobOpen(Visit $visit, bool $lock = false): void
    {
        $query = $visit->workOrder();

        if ($lock) {
            $query->lockForUpdate();
        }

        $workOrder = $query->firstOrFail();

        if ($workOrder->status === config('work_orders.completion_status')) {
            throw ValidationException::withMessages([
                'item_id' => 'Visit Items cannot be changed after the Job is completed. Reopen the Job before correcting its Item usage.',
            ]);
        }
    }

    private function assertVisitAccess(Visit $visit, User $actor, bool $manage): void
    {
        $ability = $manage ? 'work_orders.manage' : 'work_orders.view';

        abort_unless($actor->can($ability), 403);

        if ($actor->can('admin.access')) {
            return;
        }

        $assignedToVisit = $visit->assignedUsers()->whereKey($actor->getKey())->exists();
        $assignedToJob = $visit->workOrder()->whereHas(
            'assignedUsers',
            fn ($query) => $query->whereKey($actor->getKey()),
        )->exists();

        abort_unless($assignedToVisit || $assignedToJob, 403);
    }

    /** @return array{id: int, itemId: int, name: string, description: ?string, type: string, quantity: string} */
    private function present(JobItem $jobItem, Item $item): array
    {
        return [
            'id' => (int) $jobItem->getKey(),
            'itemId' => (int) $jobItem->item_id,
            'name' => $jobItem->item_name_snapshot,
            'description' => $jobItem->item_description_snapshot,
            'type' => $item->type,
            'quantity' => (string) $jobItem->quantity,
        ];
    }

    private function audit(User $actor, Visit $visit, JobItem $jobItem, string $event): void
    {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $jobItem->getMorphClass(),
            'subject_id' => $jobItem->getKey(),
            'event' => $event,
            'summary' => match ($event) {
                'visit_item_added' => $actor->name.' added a QuickBooks Item to a Visit.',
                'visit_item_removed' => $actor->name.' removed a QuickBooks Item from a Visit.',
                default => $actor->name.' updated a Visit Item quantity.',
            },
            'metadata' => [
                'visit_id' => $visit->getKey(),
                'item_id' => $jobItem->item_id,
                'quantity' => (string) $jobItem->quantity,
            ],
            'created_at' => now(),
        ]);
    }
}
