<?php

namespace Tests\Feature;

use App\Models\InstallationClaim;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TrustedDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticator_challenge_issues_a_hashed_secure_fourteen_day_device_cookie_when_requested(): void
    {
        $this->freezeTime();
        $user = $this->userWithConfirmedTwoFactor();
        $this->startChallenge($user);

        $response = $this->completeChallenge($user, trustDevice: true);
        $cookie = $response->getCookie(TrustedDeviceService::COOKIE_NAME);
        $device = TrustedDevice::query()->sole();

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame(hash('sha256', $cookie->getValue()), $device->token_hash);
        $this->assertSame(now()->addDays(14)->timestamp, $device->expires_at->timestamp);
    }

    public function test_trusted_device_skips_a_later_totp_challenge_until_its_token_is_revoked(): void
    {
        $this->claimInstallation();
        $user = $this->userWithConfirmedTwoFactor();
        $this->startChallenge($user);
        $trustedLogin = $this->completeChallenge($user, trustDevice: true);
        $trustedToken = $trustedLogin->getCookie(TrustedDeviceService::COOKIE_NAME)?->getValue();

        $this->post(route('logout'))->assertRedirect('/');
        $response = $this->withCookie(TrustedDeviceService::COOKIE_NAME, $trustedToken)
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('login.id'));
        $this->assertNotNull(TrustedDevice::query()->sole()->last_used_at);
        $this->get(route('calendar'))->assertOk();

        $this->post(route('logout'))->assertRedirect('/');
        app(TrustedDeviceService::class)->revokeAll($user);

        $response = $this->withCookie(TrustedDeviceService::COOKIE_NAME, $trustedToken)
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertSame($user->getKey(), session('login.id'));
    }

    public function test_trusted_device_token_for_one_user_cannot_skip_another_users_totp_challenge(): void
    {
        $firstUser = $this->userWithConfirmedTwoFactor();
        $secondUser = $this->userWithConfirmedTwoFactor();
        $this->startChallenge($firstUser);
        $trustedLogin = $this->completeChallenge($firstUser, trustDevice: true);
        $trustedToken = $trustedLogin->getCookie(TrustedDeviceService::COOKIE_NAME)?->getValue();

        $this->post(route('logout'))->assertRedirect('/');

        $response = $this->withCookie(TrustedDeviceService::COOKIE_NAME, $trustedToken)
            ->post(route('login.store'), [
                'email' => $secondUser->email,
                'password' => 'password',
            ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertSame($secondUser->getKey(), session('login.id'));
        $this->assertNull(TrustedDevice::query()->sole()->last_used_at);
    }

    public function test_authenticated_session_without_a_totp_proof_is_forced_through_a_challenge(): void
    {
        $this->claimInstallation();
        $user = $this->userWithConfirmedTwoFactor();

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($user->getKey(), session('login.id'));
        $this->get(route('two-factor.login'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/TwoFactorChallenge'));

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect(route('calendar'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('calendar'))->assertOk();
    }

    public function test_totp_secret_change_invalidates_the_current_session_proof(): void
    {
        $this->claimInstallation();
        $user = $this->userWithConfirmedTwoFactor();
        $this->startChallenge($user);
        $this->completeChallenge($user, trustDevice: false)->assertRedirect(route('dashboard', absolute: false));

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
        ])->save();

        $this->actingAs($user->fresh())
            ->get(route('calendar'))
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertSame($user->getKey(), session('login.id'));
    }

    public function test_recovery_code_sign_in_does_not_create_a_trusted_device(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $this->startChallenge($user);

        $response = $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'recovery-code-one',
            'trust_device' => true,
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('trusted_devices', 0);
        $this->assertNull($response->getCookie(TrustedDeviceService::COOKIE_NAME));
    }

    public function test_expired_trusted_device_cookie_is_rejected_and_its_row_is_pruned(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $token = str_repeat('a', 80);
        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(14),
        ]);
        $request = Request::create('/', 'GET', [], [TrustedDeviceService::COOKIE_NAME => $token]);
        $service = app(TrustedDeviceService::class);

        $this->assertTrue($service->isTrusted($user, $request));

        $this->travel(15)->days();

        $this->assertFalse($service->isTrusted($user, $request));
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_password_change_revokes_all_trusted_devices(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $this->createTrustedDevice($user);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'Another-Valid-Password-2026!',
            'password_confirmation' => 'Another-Valid-Password-2026!',
        ])->assertRedirect();

        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_staff_deactivation_revokes_trusted_devices(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $user = $this->userWithConfirmedTwoFactor();
        $this->createTrustedDevice($user);

        $response = $this->actingAs($admin)
            ->patch(route('admin.users.status.update', $user), ['status' => 'inactive']);

        $response->assertRedirect();
        $this->assertSame('inactive', $user->fresh()->status);
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_totp_reset_revokes_trusted_devices(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $this->createTrustedDevice($user);

        $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'password'])
            ->assertCreated();

        $this->delete(route('two-factor.disable'))->assertRedirect();

        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_user_can_forget_trusted_devices_after_password_confirmation(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $device = $this->createTrustedDevice($user);
        $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'password'])
            ->assertCreated();

        $response = $this->post(route('profile.trusted-devices.revoke'));

        $response->assertRedirect();
        $response->assertCookieExpired(TrustedDeviceService::COOKIE_NAME);
        $this->assertModelMissing($device);
    }

    public function test_sign_out_all_revokes_trusted_devices_and_the_current_session(): void
    {
        $this->claimInstallation();
        $user = $this->userWithConfirmedTwoFactor();
        $this->createTrustedDevice($user);

        $this->startChallenge($user);
        $this->completeChallenge($user, trustDevice: false)->assertRedirect(route('dashboard', absolute: false));

        $response = $this->actingAs($user)->post(route('logout.all'), ['password' => 'password']);

        $response->assertRedirect(route('login'));
        $response->assertCookieExpired(TrustedDeviceService::COOKIE_NAME);
        $this->assertGuest();
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_sign_out_all_deletes_other_database_sessions_for_the_user(): void
    {
        config(['session.driver' => 'database']);
        $this->claimInstallation();
        $user = $this->userWithConfirmedTwoFactor();
        $this->startChallenge($user);
        $this->completeChallenge($user, trustDevice: false)->assertRedirect(route('dashboard', absolute: false));

        DB::table(config('session.table'))->insert([
            'id' => 'other-session-'.$user->getKey(),
            'user_id' => $user->getKey(),
            'ip_address' => '192.0.2.1',
            'user_agent' => 'FieldOps test session',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->post(route('logout.all'), ['password' => 'password'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, DB::table(config('session.table'))->where('user_id', $user->getKey())->count());
    }

    private function userWithConfirmedTwoFactor(): User
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
                'recovery-code-one',
                'recovery-code-two',
            ], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    private function claimInstallation(): void
    {
        InstallationClaim::query()->whereKey(1)->update(['claimed_at' => now()]);
    }

    private function createTrustedDevice(User $user): TrustedDevice
    {
        return $user->trustedDevices()->create([
            'token_hash' => hash('sha256', str_repeat('b', 80)),
            'expires_at' => now()->addDays(14),
        ]);
    }

    private function startChallenge(User $user): void
    {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('two-factor.login'));
    }

    private function completeChallenge(User $user, bool $trustDevice): TestResponse
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        return $this->post(route('two-factor.login.store'), [
            'code' => $code,
            'trust_device' => $trustDevice,
        ]);
    }
}
