<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrepareQuickBooksInitialImportTestDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_command_refuses_non_local_targets_without_changing_records(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $customer = Customer::query()->create(['name' => 'Keep outside local reset scope']);

        $this->artisan('quickbooks:prepare-initial-import-test-data --force')
            ->assertExitCode(Command::FAILURE);

        $this->assertModelExists($admin->fresh());
        $this->assertModelExists($customer->fresh());
    }
}
