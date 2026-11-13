<?php

namespace Tests\Feature;

use App\Models\AdminRoleTransfer;
use App\Models\InstallationClaim;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminRoleTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_account_role_moves_only_after_the_active_verified_recipient_accepts(): void
    {
        $admin = $this->confirmedUser(admin: true);
        $recipient = $this->confirmedUser();

        $this->actingAs($admin)
            ->post(route('admin.users.admin-transfer.store', $recipient), $this->reauthentication())
            ->assertRedirect();

        $transfer = AdminRoleTransfer::query()->sole();
        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertFalse($recipient->fresh()->hasRole('admin'));

        $this->actingAs($recipient)
            ->get(route('admin.admin-role-transfers.show', $transfer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AdminRoleTransferAcceptance')
                ->where('transfer.fromName', $admin->name));

        $this->actingAs($recipient)
            ->post(route('admin.admin-role-transfers.accept', $transfer), ['confirmation' => true])
            ->assertRedirect(route('calendar'));

        $this->assertFalse($admin->fresh()->hasRole('admin'));
        $this->assertTrue($recipient->fresh()->hasRole('admin'));
        $this->assertTrue($admin->fresh()->hasRole('finance'));
        $this->assertTrue($admin->fresh()->hasRole('hr'));
        $this->assertFalse($recipient->fresh()->hasRole('finance'));
        $this->assertFalse($recipient->fresh()->hasRole('hr'));
        $this->assertSame(1, User::query()->where('status', 'active')->role('admin')->count());
        $this->assertNotNull($transfer->fresh()->completed_at);
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => $recipient->getMorphClass(),
            'subject_id' => $recipient->getKey(),
            'event' => 'admin_role_transfer_accepted',
        ]);
    }

    public function test_database_rejects_a_second_admin_account_role_assignment(): void
    {
        $this->confirmedUser(admin: true);
        $secondUser = $this->confirmedUser();

        $this->expectException(QueryException::class);

        $secondUser->assignRole('admin');
    }

    public function test_claimant_remains_admin_when_the_single_non_claimant_admin_is_reconciled_to_basic_user(): void
    {
        DB::statement('DROP INDEX model_has_roles_single_admin_user_unique');

        $claimant = $this->confirmedUser(admin: true);
        $otherAdmin = $this->confirmedUser(groups: ['management']);
        $otherAdmin->syncRoles(['admin', 'management']);
        InstallationClaim::query()->whereKey(1)->update([
            'claimed_at' => now(),
            'claimed_by_user_id' => $claimant->getKey(),
        ]);

        $migration = require database_path('migrations/2026_11_12_192000_reconcile_claimant_admin_account_role.php');
        $migration->up();

        $this->assertTrue($claimant->fresh()->hasRole('admin'));
        $this->assertTrue($otherAdmin->fresh()->hasRole('basic_user'));
        $this->assertFalse($otherAdmin->fresh()->hasRole('admin'));
        $this->assertTrue($otherAdmin->fresh()->hasRole('management'));
        $this->assertFalse($otherAdmin->fresh()->hasRole('finance'));
        $this->assertFalse($otherAdmin->fresh()->hasRole('hr'));
        $this->assertSame(1, User::query()->where('status', 'active')->role('admin')->count());
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $claimant->getKey(),
            'subject_type' => $otherAdmin->getMorphClass(),
            'subject_id' => $otherAdmin->getKey(),
            'event' => 'admin_role_reconciled',
        ]);
    }

    public function test_only_current_admin_account_holder_can_offer_transfer_to_an_eligible_user(): void
    {
        $admin = $this->confirmedUser(admin: true);
        $financeHr = $this->confirmedUser(groups: ['finance', 'hr']);
        $recipient = $this->confirmedUser();
        $unverified = $this->confirmedUser(['email_verified_at' => null]);
        $withoutTwoFactor = User::factory()->create(['status' => 'active']);

        $this->actingAs($financeHr)
            ->post(route('admin.users.admin-transfer.store', $recipient))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.admin-transfer.store', $unverified), $this->reauthentication())
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->post(route('admin.users.admin-transfer.store', $withoutTwoFactor), $this->reauthentication())
            ->assertUnprocessable();

        $this->assertSame(0, AdminRoleTransfer::query()->count());
    }

    public function test_admin_must_reauthenticate_with_password_and_fresh_totp_before_offering_transfer(): void
    {
        $admin = $this->confirmedUser(admin: true);
        $recipient = $this->confirmedUser();

        $this->actingAs($admin)
            ->from(route('admin.admin-role-transfers.create'))
            ->post(route('admin.users.admin-transfer.store', $recipient), [])
            ->assertSessionHasErrors(['password', 'totp_code'])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->actingAs($admin)
            ->post(route('admin.users.admin-transfer.store', $recipient), [
                'password' => 'incorrect-password',
                'totp_code' => $this->totpCode(),
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->actingAs($admin)
            ->post(route('admin.users.admin-transfer.store', $recipient), [
                'password' => 'password',
                'totp_code' => '000000',
            ])
            ->assertSessionHasErrors('totp_code');

        $this->assertSame(0, AdminRoleTransfer::query()->count());
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_only_the_named_recipient_can_accept_a_pending_transfer(): void
    {
        $admin = $this->confirmedUser(admin: true);
        $recipient = $this->confirmedUser();
        $otherUser = $this->confirmedUser();
        $transfer = AdminRoleTransfer::query()->create([
            'from_user_id' => $admin->getKey(),
            'to_user_id' => $recipient->getKey(),
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($otherUser)
            ->post(route('admin.admin-role-transfers.accept', $transfer), ['confirmation' => true])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertFalse($recipient->fresh()->hasRole('admin'));
        $this->assertNull($transfer->fresh()->completed_at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $groups
     */
    private function confirmedUser(array $attributes = [], bool $admin = false, array $groups = []): User
    {
        $secret = Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP');
        $user = User::factory()->create(array_replace([
            'status' => 'active',
            'email_verified_at' => now(),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ], $attributes));
        $user->syncRoles([
            ...($admin ? ['admin'] : ['basic_user']),
            ...$groups,
            ...($admin ? ['finance', 'hr'] : []),
        ]);

        return $user;
    }

    /** @return array{password: string, totp_code: string} */
    private function reauthentication(): array
    {
        return [
            'password' => 'password',
            'totp_code' => $this->totpCode(),
        ];
    }

    private function totpCode(): string
    {
        $twoFactor = app(Google2FA::class);

        return $twoFactor->oathTotp('JBSWY3DPEHPK3PXP', $twoFactor->getTimestamp() + 1);
    }
}
