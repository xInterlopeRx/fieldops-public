<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_character_search_fills_four_suggestions_when_only_customers_match(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $exact = Customer::create(['name' => 'a']);

        foreach (['Aerial Client', 'Acme Client', 'Alpha Client', 'Another Client', 'Zeta Client'] as $name) {
            Customer::create(['name' => $name]);
        }

        $this->actingAs($user)
            ->getJson(route('global.search', ['q' => 'A']))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.id', $exact->id)
            ->assertJsonPath('data.0.name', 'a')
            ->assertJsonPath('data.0.type', 'client');
    }

    public function test_global_search_includes_matches_from_customer_contact_and_site_details(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create(['name' => 'Profile Search Company']);
        Contact::factory()->for($customer)->create([
            'contact_type' => 'other',
            'email' => 'needle.contact@example.test',
        ]);
        $customer->sites()->create([
            'name' => 'North Field Site',
            'address' => '842 Needlebrook Road',
        ]);

        $this->actingAs($user)
            ->getJson(route('global.search', ['q' => 'needle.contact@example.test']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.type', 'client');

        $this->getJson(route('global.search', ['q' => 'Needlebrook']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.type', 'client');
    }

    public function test_global_search_prioritizes_one_suggestion_per_matching_section(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create(['name' => 'Sharedneedle Company']);
        $site = $customer->sites()->create(['name' => 'Sharedneedle Site']);
        Project::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Sharedneedle Project',
        ]);
        $job = WorkOrder::query()->create([
            'site_id' => $site->id,
            'job_number' => 'SHARED-1',
            'title' => 'Sharedneedle Job',
            'status' => 'pending',
        ]);
        $job->assignedUsers()->attach($user->id);
        Visit::query()->create([
            'work_order_id' => $job->id,
            'scheduled_at' => now(),
            'visit_type' => 'initial',
            'status' => 'scheduled',
            'notes' => 'Sharedneedle Visit',
        ]);

        $this->actingAs($user)
            ->getJson(route('global.search', ['q' => 'sharedneedle']))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.type', 'client')
            ->assertJsonPath('data.1.type', 'project')
            ->assertJsonPath('data.2.type', 'job')
            ->assertJsonPath('data.3.type', 'visit');
    }

    public function test_global_search_keeps_work_order_suggestions_limited_to_the_current_user(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $customer = Customer::create(['name' => 'Search Test Customer']);
        $site = $customer->sites()->create(['name' => 'Search Test Site']);
        $assignedJob = WorkOrder::query()->create([
            'site_id' => $site->id,
            'job_number' => 'SEARCH-1',
            'title' => 'needle assigned job',
            'status' => 'pending',
        ]);
        $assignedJob->assignedUsers()->attach($user->id);
        $unassignedJob = WorkOrder::query()->create([
            'site_id' => $site->id,
            'job_number' => 'SEARCH-2',
            'title' => 'needle private job',
            'status' => 'pending',
        ]);
        $unassignedJob->assignedUsers()->attach($otherUser->id);

        $this->actingAs($user)
            ->getJson(route('global.search', ['q' => 'needle']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedJob->id)
            ->assertJsonPath('data.0.name', 'needle assigned job');
    }

    public function test_empty_search_returns_no_suggestions_and_oversized_terms_are_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('global.search'))
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->getJson(route('global.search', ['q' => str_repeat('a', 101)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_guest_cannot_access_global_search(): void
    {
        $this->getJson(route('global.search', ['q' => 'a']))
            ->assertUnauthorized();
    }
}
