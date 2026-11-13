<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_a_basic_user_with_multiple_notification_roles(): void
    {
        $this->markExistingInstallationReady();
        $admin = User::factory()->admin()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.users.invitations.store'), [
                'name' => 'Office teammate',
                'email' => 'office@example.test',
                'roles' => ['basic_user', 'management', 'billing'],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['basic_user', 'management', 'billing'],
            UserInvitation::query()->where('email', 'office@example.test')->sole()->roles,
        );
    }

    public function test_admin_cannot_assign_the_admin_account_role_through_an_invitation(): void
    {
        $this->markExistingInstallationReady();
        $admin = User::factory()->admin()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->from(route('admin.users'))
            ->post(route('admin.users.invitations.store'), [
                'name' => 'Invalid invite',
                'email' => 'invalid@example.test',
                'roles' => ['admin'],
            ])
            ->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('user_invitations', ['email' => 'invalid@example.test']);
    }

    public function test_invitation_acceptance_assigns_all_selected_roles(): void
    {
        $this->markExistingInstallationReady();
        Notification::fake();
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $token = Str::random(64);
        $invitation = UserInvitation::query()->create([
            'invited_by' => $admin->id,
            'name' => 'Office teammate',
            'email' => 'office@example.test',
            'roles' => ['basic_user', 'management', 'billing'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->post(route('invitation.accept', $token), [
            'password' => 'Valid-Password-2026!',
            'password_confirmation' => 'Valid-Password-2026!',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', $invitation->email)->firstOrFail();
        $this->assertEqualsCanonicalizing(['basic_user', 'management', 'billing'], $user->getRoleNames()->all());
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get(route('calendar'))->assertRedirect(route('verification.notice'));
    }

    public function test_admin_can_add_notification_roles_without_granting_their_permissions_to_basic_users(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $staff = User::factory()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->patch(route('admin.users.roles.update', $staff), [
                'security_groups' => [],
                'notification_roles' => ['management', 'billing'],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(['basic_user', 'management', 'billing'], $staff->fresh()->getRoleNames()->all());
        $this->assertTrue($staff->fresh()->can('notifications.management'));
        $this->assertTrue($staff->fresh()->can('notifications.billing'));
        $this->assertFalse($staff->fresh()->can('admin.access'));
        $this->assertTrue($staff->fresh()->can('work_orders.manage'));

        $notificationOnly = User::factory()->create(['status' => 'active']);
        $notificationOnly->syncRoles(['management']);
        $this->assertTrue($notificationOnly->can('notifications.management'));
        $this->assertFalse($notificationOnly->can('work_orders.manage'));
        $this->assertFalse($notificationOnly->can('admin.access'));
        $this->actingAs($notificationOnly)
            ->get(route('calendar'))
            ->assertForbidden();
    }

    public function test_hr_can_assign_multiple_security_groups_from_the_staff_profile(): void
    {
        $hr = User::factory()->create(['status' => 'active']);
        $hr->syncRoles(['basic_user', 'hr']);
        $staff = User::factory()->create(['status' => 'active']);

        $this->actingAs($hr)
            ->get(route('admin.users'))
            ->assertOk();

        $this->actingAs($hr)
            ->patch(route('admin.users.roles.update', $staff), [
                'security_groups' => ['finance', 'hr'],
                'notification_roles' => ['management'],
            ])
            ->assertRedirect();

        $staff->refresh();
        $this->assertEqualsCanonicalizing(
            ['basic_user', 'finance', 'hr', 'management'],
            $staff->getRoleNames()->all(),
        );
        $this->assertTrue($staff->can('time_entries.view_all'));
        $this->assertTrue($staff->can('time_entries.view_financial'));
        $this->assertTrue($staff->can('time_entries.review'));
        $this->assertTrue($staff->can('users.manage'));
    }

    public function test_finance_user_cannot_manage_staff_roles(): void
    {
        $finance = User::factory()->create(['status' => 'active']);
        $finance->syncRoles(['basic_user', 'finance']);
        $staff = User::factory()->create(['status' => 'active']);

        $this->actingAs($finance)
            ->get(route('admin.users'))
            ->assertForbidden();

        $this->actingAs($finance)
            ->get(route('admin.projects'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Projects'));

        $this->actingAs($finance)
            ->get(route('admin.archive'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Archive'));

        $this->actingAs($finance)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('auth.can_view_sync_logs', true)
                ->where('auth.can_manage_users', false));

        $this->actingAs($finance)
            ->get(route('admin.time-tracking.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/TimeTracking/Dashboard'));

        $this->actingAs($finance)
            ->patch(route('admin.users.roles.update', $staff), [
                'security_groups' => ['hr'],
                'notification_roles' => [],
            ])
            ->assertForbidden();

        $this->assertEqualsCanonicalizing(['basic_user'], $staff->fresh()->getRoleNames()->all());
    }

    public function test_admin_without_security_groups_gets_archive_logs_and_shared_team_timesheets_but_not_staff_or_financial_time_tracking(): void
    {
        $admin = User::factory()->adminWithoutSecurityGroups()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.archive'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Archive'));

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('auth.can_view_sync_logs', true));

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/TimeTracking/Team'));

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.billing'))
            ->assertForbidden();
    }

    public function test_staff_profile_cannot_assign_admin_account_role_and_last_admin_cannot_be_deactivated(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $staff = User::factory()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->patch(route('admin.users.roles.update', $staff), [
                'security_groups' => ['admin'],
                'notification_roles' => [],
            ])
            ->assertSessionHasErrors('security_groups.0');

        $this->actingAs($admin)
            ->patch(route('admin.users.status.update', $admin), ['status' => 'inactive'])
            ->assertUnprocessable();

        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertSame('active', $admin->fresh()->status);
        $this->assertEqualsCanonicalizing(['basic_user'], $staff->fresh()->getRoleNames()->all());
    }

    private function markExistingInstallationReady(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'onboarding_required' => false,
            'readiness_completed_at' => now(),
            'readiness_receipt_type' => 'legacy',
        ]);
    }
}
