<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\Item;
use App\Models\JobItem;
use App\Models\QuickBooksItemCatalogSync;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VisitJobItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_item_search_returns_only_active_selectable_items_from_the_connected_company(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->connectQuickBooks();
        $visit = $this->visit();
        $service = $this->item('101', 'Repair service', Item::TYPE_SERVICE, description: 'Replace the synthetic filter.');
        $this->item('102', 'Inventory supply', Item::TYPE_INVENTORY, active: false);
        $this->item('103', 'Category', Item::TYPE_CATEGORY);
        $this->item('104', 'Foreign service', Item::TYPE_SERVICE, realmId: 'another-company');
        Http::preventStrayRequests();

        $this->actingAs($admin)
            ->getJson(route('visits.items.search', $visit).'?search=repair')
            ->assertOk()
            ->assertJsonCount(1, 'data.catalog')
            ->assertJsonPath('data.catalog.0.id', $service->getKey())
            ->assertJsonPath('data.catalog.0.name', 'Repair service')
            ->assertJsonPath('data.catalog.0.description', 'Replace the synthetic filter.')
            ->assertJsonPath('data.catalog.0.type', Item::TYPE_SERVICE)
            ->assertJsonCount(0, 'data.items');

        Http::assertNothingSent();
    }

    public function test_visit_item_usage_can_be_added_updated_and_removed_without_financial_fields(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->connectQuickBooks();
        $visit = $this->visit();
        $item = $this->item('201', 'Synthetic service', Item::TYPE_SERVICE, description: 'Synthetic service description.');
        Http::preventStrayRequests();

        $this->actingAs($admin)
            ->postJson(route('visits.items.store', $visit), [
                'item_id' => $item->getKey(),
                'quantity' => '1.250',
            ])
            ->assertCreated()
            ->assertJsonPath('data.itemId', $item->getKey())
            ->assertJsonPath('data.name', 'Synthetic service')
            ->assertJsonPath('data.description', 'Synthetic service description.')
            ->assertJsonPath('data.quantity', '1.250');

        $jobItem = JobItem::query()->sole();
        $this->assertSame('201', $jobItem->quickbooks_item_id);
        $this->assertSame('1.250', $jobItem->quantity);
        $this->assertSame('Synthetic service description.', $jobItem->item_description_snapshot);
        $this->assertFalse(Schema::hasColumn('job_items', 'price'));
        $this->assertFalse(Schema::hasColumn('job_items', 'cost'));

        $this->putJson(route('visits.items.update', ['visit' => $visit, 'jobItem' => $jobItem]), [
            'quantity' => '2.125',
        ])->assertOk()->assertJsonPath('data.quantity', '2.125');

        $this->assertSame('2.125', $jobItem->fresh()->quantity);

        $this->deleteJson(route('visits.items.destroy', ['visit' => $visit, 'jobItem' => $jobItem]))
            ->assertOk();

        $this->assertDatabaseCount('job_items', 0);
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => JobItem::class,
            'subject_id' => $jobItem->getKey(),
            'event' => 'visit_item_removed',
        ]);
        Http::assertNothingSent();
    }

    public function test_duplicate_add_updates_the_visit_line_instead_of_creating_a_second_quantity_row(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->connectQuickBooks();
        $visit = $this->visit();
        $item = $this->item('202', 'Synthetic non-inventory', Item::TYPE_NON_INVENTORY);

        $this->actingAs($admin);
        foreach (['1', '3.5'] as $quantity) {
            $this->postJson(route('visits.items.store', $visit), [
                'item_id' => $item->getKey(),
                'quantity' => $quantity,
            ])->assertCreated();
        }

        $this->assertDatabaseCount('job_items', 1);
        $this->assertSame('3.500', JobItem::query()->sole()->quantity);
    }

    public function test_visit_item_usage_rejects_invalid_or_foreign_items_and_completed_jobs(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->connectQuickBooks();
        $visit = $this->visit();
        $foreignItem = $this->item('203', 'Other company Item', Item::TYPE_SERVICE, realmId: 'another-company');
        $category = $this->item('204', 'Category', Item::TYPE_CATEGORY);
        $this->actingAs($admin);

        $this->postJson(route('visits.items.store', $visit), [
            'item_id' => $foreignItem->getKey(),
            'quantity' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('item_id');

        $this->postJson(route('visits.items.store', $visit), [
            'item_id' => $category->getKey(),
            'quantity' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('item_id');

        $this->postJson(route('visits.items.store', $visit), [
            'item_id' => $foreignItem->getKey(),
            'quantity' => '0.000',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $visit->workOrder->update(['status' => config('work_orders.completion_status')]);
        $selectable = $this->item('205', 'Completed job Item', Item::TYPE_SERVICE);
        $this->postJson(route('visits.items.store', $visit), [
            'item_id' => $selectable->getKey(),
            'quantity' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('item_id');

        $this->assertDatabaseCount('job_items', 0);
    }

    public function test_basic_user_cannot_manage_visit_item_usage(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        $visit = $this->visit();
        $item = $this->item('206', 'Restricted Item', Item::TYPE_SERVICE);
        $basicUser = User::factory()->create(['status' => 'active']);

        $this->actingAs($basicUser)
            ->postJson(route('visits.items.store', $visit), [
                'item_id' => $item->getKey(),
                'quantity' => '1',
            ])->assertForbidden();

        $this->assertDatabaseCount('job_items', 0);
    }

    public function test_job_workspace_summarizes_visit_items_without_financial_values(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->connectQuickBooks();
        $visit = $this->visit();
        $item = $this->item('207', 'Filter replacement', Item::TYPE_NON_INVENTORY);
        JobItem::query()->create([
            'visit_id' => $visit->id,
            'item_id' => $item->id,
            'quickbooks_item_id' => $item->quickbooks_id,
            'item_name_snapshot' => $item->name,
            'quantity' => '2.000',
            'added_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.jobs.workspace.show', $visit->work_order_id))
            ->assertOk()
            ->assertJsonPath('data.visits.0.items.0.name', 'Filter replacement')
            ->assertJsonPath('data.visits.0.items.0.quantity', '2.000')
            ->assertJsonMissingPath('data.visits.0.items.0.price')
            ->assertJsonMissingPath('data.visits.0.items.0.cost');
    }

    public function test_assigned_technician_can_add_item_usage_from_the_calendar_job_view(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        $technician = User::factory()->create(['status' => 'active']);
        $visit = $this->visit();
        $visit->workOrder->assignedUsers()->attach($technician->id);
        $item = $this->item('208', 'Technician selected Item', Item::TYPE_SERVICE);

        $this->actingAs($technician)
            ->getJson(route('work-orders.show', $visit->work_order_id))
            ->assertOk()
            ->assertJsonPath('data.visits.0.items_editable', true);

        $this->postJson(route('visits.items.store', $visit), [
            'item_id' => $item->getKey(),
            'quantity' => '1.5',
        ])->assertCreated()->assertJsonPath('data.quantity', '1.500');

        $this->assertDatabaseHas('job_items', [
            'visit_id' => $visit->id,
            'item_id' => $item->id,
            'quickbooks_item_id' => '208',
            'quantity' => 1.5,
        ]);
    }

    private function connectQuickBooks(): IntegrationConnection
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API,
            'onboarding_required' => false,
        ]);

        $connection = IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'visit-item-realm',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now(),
        ]);
        QuickBooksItemCatalogSync::query()->create([
            'environment' => app()->environment(),
            'realm_id' => $connection->realm_id,
            'status' => QuickBooksItemCatalogSync::STATUS_COMPLETE,
            'next_start_position' => 1,
            'total_items' => 0,
            'processed_items' => 0,
            'imported_items' => 0,
            'completed_at' => now(),
        ]);

        return $connection;
    }

    private function visit(): Visit
    {
        $customer = Customer::query()->create(['name' => 'Visit Item Customer']);
        $site = Site::query()->create(['customer_id' => $customer->getKey(), 'name' => 'Visit Item Site']);
        $workOrder = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'job_number' => 'J-ITEM-1',
            'title' => 'Visit Item Job',
            'scope' => 'Use selected Items.',
            'status' => 'scheduled',
        ]);

        return $workOrder->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Record used Items.',
        ]);
    }

    private function item(
        string $id,
        string $name,
        string $type,
        bool $active = true,
        string $realmId = 'visit-item-realm',
        ?string $description = null,
    ): Item {
        return Item::query()->create([
            'quickbooks_realm_id' => $realmId,
            'quickbooks_id' => $id,
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'active' => $active,
            'last_synced_at' => now(),
        ]);
    }
}
