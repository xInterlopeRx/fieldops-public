<?php

namespace Tests\Feature;

use App\Models\InstallationClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class RequiredTwoFactorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimed_installation_redirects_staff_without_confirmed_totp_to_profile_security_settings(): void
    {
        $this->claimInstallation();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Set up and confirm two-factor authentication before using FieldOps.');

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('twoFactorEnabled', false));
    }

    public function test_unconfirmed_totp_enrollment_does_not_unlock_the_business_routes(): void
    {
        $this->claimInstallation();
        $user = User::factory()->create();
        $this->setTwoFactorAuthentication($user, confirmed: false);

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertRedirect(route('profile.edit'));
    }

    public function test_confirmed_totp_allows_access_after_the_authenticator_challenge(): void
    {
        $this->claimInstallation();
        $user = User::factory()->create();
        $this->setTwoFactorAuthentication($user, confirmed: true);
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->get(route('calendar'))->assertOk();
    }

    public function test_email_verification_is_required_before_totp_setup_access(): void
    {
        $this->claimInstallation();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertRedirect(route('verification.notice'));
    }

    private function claimInstallation(): void
    {
        InstallationClaim::query()->whereKey(1)->update(['claimed_at' => now()]);
    }

    private function setTwoFactorAuthentication(User $user, bool $confirmed): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
                'first-recovery-code',
                'second-recovery-code',
            ], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => $confirmed ? now() : null,
        ])->save();
    }
}
