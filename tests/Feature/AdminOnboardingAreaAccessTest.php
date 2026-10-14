<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminOnboardingAreaAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_must_confirm_password_and_fresh_totp_before_opening_the_onboarding_area(): void
    {
        [$admin, $secret] = $this->createAdminWithTotp();
        $this->loginWithTotp($admin, $secret);

        $this->get(route('admin.onboarding.access'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/OnboardingAreaAccess')
                ->where('auth.onboarding_area_unlocked', false));

        $this->from(route('admin.onboarding.access'))
            ->post(route('admin.onboarding.access.unlock'), [
                'password' => 'incorrect-password',
                'totp_code' => $this->nextTotpCode($secret),
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->post(route('admin.onboarding.access.unlock'), [
            'password' => 'password',
            'totp_code' => '000000',
        ])->assertSessionHasErrors('totp_code');

        $this->assertFalse(session()->has('admin_onboarding_area_access'));

        $this->post(route('admin.onboarding.access.unlock'), [
            'password' => 'password',
            'totp_code' => $this->nextTotpCode($secret),
        ])->assertRedirect(route('admin.onboarding'));

        $this->assertTrue(session()->has('admin_onboarding_area_access'));
        $this->get(route('admin.integrations.quickbooks.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/QuickBooksIntegration')
                ->where('auth.onboarding_area_unlocked', true));
    }

    public function test_direct_integration_routes_redirect_to_the_auth_gate_and_return_to_that_integration(): void
    {
        [$admin, $secret] = $this->createAdminWithTotp();
        $this->loginWithTotp($admin, $secret);

        $this->get(route('admin.integrations.bitwarden.show'))
            ->assertRedirect(route('admin.onboarding.access'));

        $this->assertSame(
            'admin.integrations.bitwarden.show',
            session('admin_onboarding_area_intended_route'),
        );

        $this->post(route('admin.onboarding.access.unlock'), [
            'password' => 'password',
            'totp_code' => $this->nextTotpCode($secret),
        ])->assertRedirect(route('admin.integrations.bitwarden.show'));

        $this->get(route('admin.integrations.bitwarden.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BitwardenSecretManagerConnection')
                ->where('auth.onboarding_area_unlocked', true));
    }

    public function test_unprivileged_staff_cannot_open_the_onboarding_area(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->get(route('admin.onboarding.access'))
            ->assertForbidden();
    }

    public function test_direct_integration_actions_are_blocked_until_the_area_is_unlocked(): void
    {
        [$admin, $secret] = $this->createAdminWithTotp();
        $this->loginWithTotp($admin, $secret);
        Queue::fake();
        Http::preventStrayRequests();

        $this->post(route('admin.integrations.quickbooks.items.sync'), [
            'confirm_sync' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding.access'));

        $this->assertSame(
            'admin.integrations.quickbooks.show',
            session('admin_onboarding_area_intended_route'),
        );
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_onboarding_area_expires_after_one_hour_of_inactivity_and_activity_extends_the_unlock(): void
    {
        [$admin, $secret] = $this->createAdminWithTotp();
        $this->loginWithTotp($admin, $secret);
        $this->get(route('admin.onboarding.access'));
        $this->post(route('admin.onboarding.access.unlock'), [
            'password' => 'password',
            'totp_code' => $this->nextTotpCode($secret),
        ])->assertRedirect(route('admin.onboarding'));

        $this->travel(59)->minutes();
        $this->get(route('admin.integrations.quickbooks.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.onboarding_area_unlocked', true));

        $this->travel(59)->minutes();
        $this->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/MicrosoftGraphOnboardingTargets')
                ->where('auth.onboarding_area_unlocked', true));

        $this->travel(61)->minutes();
        $this->get(route('admin.onboarding'))
            ->assertRedirect(route('admin.onboarding.access'));

        $this->assertFalse(session()->has('admin_onboarding_area_access'));
        $this->assertSame('admin.onboarding', session('admin_onboarding_area_intended_route'));
    }

    public function test_access_grant_is_bound_to_the_authenticating_user_and_current_credentials(): void
    {
        [$admin, $secret] = $this->createAdminWithTotp();
        $this->loginWithTotp($admin, $secret);
        $this->get(route('admin.onboarding.access'));
        $this->post(route('admin.onboarding.access.unlock'), [
            'password' => 'password',
            'totp_code' => $this->nextTotpCode($secret),
        ])->assertRedirect(route('admin.onboarding'));

        $admin->forceFill(['password' => 'A-new-password-2026!'])->save();
        $this->actingAs($admin->fresh());

        $this->get(route('admin.onboarding'))
            ->assertRedirect(route('admin.onboarding.access'));

        $this->assertFalse(session()->has('admin_onboarding_area_access'));
    }

    /** @return array{User, string} */
    private function createAdminWithTotp(): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin = User::factory()->admin()->create([
            'status' => 'active',
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        return [$admin, $secret];
    }

    private function loginWithTotp(User $user, string $secret): void
    {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    private function nextTotpCode(string $secret): string
    {
        $googleTwoFactor = app(Google2FA::class);

        return $googleTwoFactor->oathTotp($secret, $googleTwoFactor->getTimestamp() + 1);
    }
}
