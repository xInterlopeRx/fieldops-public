<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_operations_home(): void
    {
        $admin = User::factory()->admin()->create([
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.home'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Home'));
    }

    public function test_basic_user_cannot_view_administration_home(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('admin.home'))
            ->assertForbidden();
    }
}
