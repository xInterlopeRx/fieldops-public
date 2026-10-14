<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksApiException;
use App\Models\ApplicationSetupState;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\QuickBooksCustomerImportService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickBooksCustomerImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
    }

    public function test_customer_replace_command_refuses_to_run_without_explicit_reset_option(): void
    {
        $customer = Customer::create(['name' => 'Keep this local row']);

        $this->artisan('quickbooks:import-customers')
            ->expectsOutput('This import requires --replace-business-data because it replaces local business records.')
            ->assertExitCode(Command::FAILURE);

        $this->assertModelExists($customer->fresh());
    }

    public function test_complete_flat_sandbox_dataset_replaces_business_data_and_preserves_staff_and_connection(): void
    {
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/query*' => Http::response([
                'QueryResponse' => [
                    'Customer' => [$this->parentCustomer(), $this->childCustomer()],
                    'startPosition' => 1,
                    'maxResults' => 1000,
                    'totalCount' => 2,
                ],
            ]),
        ]);
        $user = User::factory()->admin()->create(['status' => 'active']);
        $connection = $this->quickBooksConnection($user);
        $organization = Organization::query()->create(['name' => 'Old seeded organization']);
        $oldCustomer = Customer::create(['name' => 'Seeded local customer']);
        $oldSite = $oldCustomer->sites()->create(['name' => 'Seeded local site']);
        $oldProject = Project::query()->create([
            'customer_id' => $oldCustomer->id,
            'name' => 'Seeded local project',
            'status' => 'active',
        ]);
        $oldProject->sites()->attach($oldSite);
        $oldWorkOrder = WorkOrder::query()->create([
            'site_id' => $oldSite->id,
            'job_number' => 'J99999',
            'title' => 'Seeded local job',
            'status' => 'pending',
        ]);
        $oldWorkOrder->assignedUsers()->attach($user);
        $oldVisit = Visit::query()->create([
            'work_order_id' => $oldWorkOrder->id,
            'visit_type' => 'initial',
            'scheduled_at' => now(),
        ]);
        $oldVisit->assignedUsers()->attach($user);

        $result = app(QuickBooksCustomerImportService::class)->replaceBusinessData();

        $this->assertSame(['customers' => 1, 'sites' => 1, 'qbo_records' => 2], $result);
        $this->assertDatabaseHas('customers', [
            'client_id' => 'C01001',
            'quickbooks_id' => '100',
            'name' => 'Sandbox Parent',
            'quickbooks_sync_status' => 'synced',
        ]);
        $this->assertDatabaseHas('sites', [
            'quickbooks_id' => '101',
            'quickbooks_parent_id' => '100',
            'name' => 'Sandbox Child',
        ]);
        $this->assertDatabaseHas('contacts', [
            'site_id' => Site::query()->where('quickbooks_id', '101')->value('id'),
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'email' => 'child@example.test',
        ]);
        $this->assertDatabaseMissing('customers', ['id' => $oldCustomer->id]);
        $this->assertDatabaseMissing('projects', ['id' => $oldProject->id]);
        $this->assertDatabaseMissing('work_orders', ['id' => $oldWorkOrder->id]);
        $this->assertDatabaseMissing('visits', ['id' => $oldVisit->id]);
        $this->assertModelExists($user->fresh());
        $this->assertModelExists($connection->fresh());
        $this->assertModelExists($organization->fresh());
    }

    public function test_nested_or_orphaned_qbo_hierarchy_is_rejected_before_clearing_local_data(): void
    {
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/query*' => Http::response([
                'QueryResponse' => [
                    'Customer' => [
                        $this->parentCustomer(),
                        $this->childCustomer(),
                        [
                            'Id' => '102',
                            'DisplayName' => 'Nested Child',
                            'Job' => true,
                            'ParentRef' => ['value' => '101'],
                            'Level' => 2,
                        ],
                    ],
                    'startPosition' => 1,
                    'maxResults' => 1000,
                    'totalCount' => 3,
                ],
            ]),
        ]);
        $this->quickBooksConnection();
        $oldCustomer = Customer::create(['name' => 'Keep this local row']);

        try {
            app(QuickBooksCustomerImportService::class)->replaceBusinessData();
            $this->fail('Expected nested QBO customer hierarchy to be rejected.');
        } catch (QuickBooksApiException $exception) {
            $this->assertSame('nested_or_orphaned_customer_hierarchy', $exception->reason);
        }

        $this->assertModelExists($oldCustomer->fresh());
        $this->assertDatabaseCount('customers', 1);
    }

    private function quickBooksConnection(?User $user = null): IntegrationConnection
    {
        $this->fakeIntuitBwsCredentials();

        return IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'sandbox-realm-123',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_by_user_id' => $user?->id,
            'connected_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function parentCustomer(): array
    {
        return [
            'Id' => '100',
            'SyncToken' => '0',
            'DisplayName' => 'Sandbox Parent',
            'FullyQualifiedName' => 'Sandbox Parent',
            'Job' => false,
            'Active' => true,
            'PrimaryEmailAddr' => ['Address' => 'parent@example.test'],
        ];
    }

    /** @return array<string, mixed> */
    private function childCustomer(): array
    {
        return [
            'Id' => '101',
            'SyncToken' => '0',
            'DisplayName' => 'Sandbox Child',
            'FullyQualifiedName' => 'Sandbox Parent:Sandbox Child',
            'ParentRef' => ['value' => '100', 'name' => 'Sandbox Parent'],
            'Job' => true,
            'Level' => 1,
            'Active' => true,
            'PrimaryEmailAddr' => ['Address' => 'child@example.test'],
        ];
    }
}
