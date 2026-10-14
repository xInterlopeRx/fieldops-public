<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_login_requires_a_two_factor_challenge_for_enrolled_users(): void
    {
        $user = User::factory()->create();
        $this->addTwoFactorAuthentication($user, confirmed: true);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $response->assertSessionHas('login.id', $user->id);

        $challengePage = $this->get(route('two-factor.login'));
        $challengePage->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));

        $challenge = $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'one-time-recovery-code',
        ]);

        $challenge->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $challenge->assertSessionMissing('login.id');
        $this->assertNotContains('one-time-recovery-code', $user->fresh()->recoveryCodes());
    }

    public function test_invalid_two_factor_code_does_not_authenticate_the_user(): void
    {
        $user = User::factory()->create();
        $this->addTwoFactorAuthentication($user, confirmed: true);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response = $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'not-a-valid-code',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        $response->assertSessionHas('login.id', $user->id);
    }

    public function test_two_factor_challenges_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->addTwoFactorAuthentication($user, confirmed: true);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson(route('two-factor.login.store'), [
                'recovery_code' => 'invalid-code-'.$attempt,
            ])->assertUnprocessable();
        }

        $this->postJson(route('two-factor.login.store'), [
            'recovery_code' => 'another-invalid-code',
        ])->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_inactive_staff_users_cannot_authenticate(): void
    {
        $user = User::factory()->create(['status' => 'inactive']);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_users_can_enroll_and_confirm_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('password.confirm'));

        $this->postJson(route('password.confirm.store'), ['password' => 'password'])
            ->assertCreated();

        $this->postJson(route('two-factor.enable'))->assertOk();

        $user->refresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        $this->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('twoFactorPending', true)
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes')
            ->missing('auth.user.two_factor_confirmed_at')
            ->etc());

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson(route('two-factor.confirm'), ['code' => $code])->assertOk();

        $user->refresh();

        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(8, $user->recoveryCodes());
    }

    public function test_two_factor_settings_require_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();
        $this->addTwoFactorAuthentication($user, confirmed: true);

        $response = $this->actingAs($user)->delete(route('two-factor.disable'));

        $response->assertRedirect(route('password.confirm'));
        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    private function addTwoFactorAuthentication(User $user, bool $confirmed = false): void
    {
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-two-factor-secret'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
                'one-time-recovery-code',
                'another-recovery-code',
            ], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => $confirmed ? now() : null,
        ])->save();
    }
}
