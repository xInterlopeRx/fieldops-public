<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

    public function test_admin_cannot_create_an_invitation_without_exactly_one_account_role(): void
    {
        $this->markExistingInstallationReady();
        $admin = User::factory()->admin()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->from(route('admin.users'))
            ->post(route('admin.users.invitations.store'), [
                'name' => 'Invalid invite',
                'email' => 'invalid@example.test',
                'roles' => ['admin', 'basic_user', 'management'],
            ])
            ->assertSessionHasErrors('roles');

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
                'roles' => ['basic_user', 'management', 'billing'],
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

    public function test_user_cannot_remove_its_own_admin_role_or_the_last_active_admin_role(): void
    {
        $soleAdmin = User::factory()->admin()->create(['status' => 'active']);

        $this->actingAs($soleAdmin)
            ->patch(route('admin.users.roles.update', $soleAdmin), ['roles' => ['basic_user']])
            ->assertUnprocessable();
        $this->actingAs($soleAdmin)
            ->patch(route('admin.users.status.update', $soleAdmin), ['status' => 'inactive'])
            ->assertUnprocessable();

        $anotherAdmin = User::factory()->admin()->create(['status' => 'active']);
        $this->actingAs($anotherAdmin)
            ->patch(route('admin.users.status.update', $soleAdmin), ['status' => 'inactive'])
            ->assertRedirect();

        $this->actingAs($anotherAdmin)
            ->patch(route('admin.users.roles.update', $anotherAdmin), ['roles' => ['basic_user']])
            ->assertUnprocessable();

        $this->actingAs($anotherAdmin)
            ->patch(route('admin.users.roles.update', $soleAdmin), ['roles' => ['basic_user']])
            ->assertRedirect();

        $this->assertSame('inactive', $soleAdmin->fresh()->status);
        $this->assertTrue($anotherAdmin->fresh()->isAdmin());
        $this->assertFalse($soleAdmin->fresh()->hasRole('admin'));
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
