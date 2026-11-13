<?php

namespace Tests\Feature\Auth;

use App\Mail\BootstrapSmtpTestMail;
use App\Mail\SmtpBootstrapDiagnosticTransport;
use App\Models\InstallationClaim;
use App\Models\MailTransportConfiguration;
use App\Models\User;
use App\Services\SmtpBootstrapConfiguration;
use App\Services\SmtpBootstrapDiagnosticTransportFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled(): void
    {
        $response = $this->get('/register');

        $response->assertNotFound();
    }

    public function test_public_registration_cannot_create_users(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertNotFound();
    }

    public function test_empty_installation_exposes_the_one_time_first_admin_form(): void
    {
        $this->get(route('installation.bootstrap.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Auth/FirstAdminBootstrap'));

        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('canBootstrap', true));
    }

    public function test_first_admin_claim_is_atomic_one_time_and_requires_email_verification(): void
    {
        Mail::fake();
        Notification::fake();
        config(['mail.default' => 'log']);

        $smtpPayload = $this->smtpTestPayload();
        $this->post(route('installation.bootstrap.smtp-test'), $smtpPayload)
            ->assertRedirect(route('installation.bootstrap.create'));

        $this->assertSame('log', config('mail.default'));

        $pendingConfiguration = MailTransportConfiguration::query()->sole();
        $this->assertNull($pendingConfiguration->configured_by_user_id);
        $this->assertSame('initial.admin@example.test', $pendingConfiguration->tested_recipient);
        $this->assertSame('smtp-test-password', $pendingConfiguration->password);
        $this->assertNotSame(
            'smtp-test-password',
            DB::table('mail_transport_configurations')->where('singleton_key', MailTransportConfiguration::PRIMARY)->value('password'),
        );
        $this->get(route('installation.bootstrap.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/FirstAdminBootstrap')
                ->where('smtp_backup.host', 'smtp.example.test')
                ->where('smtp_backup.tested_recipient', 'initial.admin@example.test')
                ->missing('smtp_backup.username')
                ->missing('smtp_backup.password'))
            ->assertDontSee('smtp-test-user')
            ->assertDontSee('smtp-test-password');

        $response = $this->post(route('installation.bootstrap.store'), $this->firstAdminPayload());

        $response->assertRedirect(route('verification.notice'));
        $user = User::query()->sole();
        $claim = InstallationClaim::query()->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('initial.admin@example.test', $user->email);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('finance'));
        $this->assertTrue($user->hasRole('hr'));
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($claim->claimed_at);
        $this->assertSame($user->getKey(), $claim->claimed_by_user_id);
        Notification::assertSentTo($user, VerifyEmail::class);
        Mail::assertSent(BootstrapSmtpTestMail::class, fn (BootstrapSmtpTestMail $mail): bool => $mail->hasTo($user->email));
        $mailConfiguration = MailTransportConfiguration::query()->sole();
        $this->assertSame('smtp.example.test', $mailConfiguration->host);
        $this->assertSame('smtp-test-user', $mailConfiguration->username);
        $this->assertSame('smtp-test-password', $mailConfiguration->password);
        $this->assertNotSame(
            'smtp-test-user',
            DB::table('mail_transport_configurations')->where('singleton_key', MailTransportConfiguration::PRIMARY)->value('username'),
        );
        $this->assertNotSame(
            'smtp-test-password',
            DB::table('mail_transport_configurations')->where('singleton_key', MailTransportConfiguration::PRIMARY)->value('password'),
        );
        $this->assertSame('smtp', config('mail.default'));

        config(['mail.default' => 'log']);
        $this->assertTrue(app(SmtpBootstrapConfiguration::class)->applyStored());
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame('smtp-test-password', config('mail.mailers.smtp.password'));
        $this->assertTrue(config('mail.mailers.smtp.verify_peer'));
        $this->get(route('verification.notice'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/VerifyEmail')
                ->where('status', 'bootstrap-smtp-ready'));
        $this->get(route('calendar'))->assertRedirect(route('verification.notice'));

        auth()->logout();
        $this->get(route('installation.bootstrap.create'))
            ->assertRedirect(route('login'));

        $this->post(route('installation.bootstrap.store'), $this->firstAdminPayload(
            name: 'Second Admin',
            email: 'second.admin@example.test',
        ))->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 1);
        Mail::assertSentTimes(BootstrapSmtpTestMail::class, 1);
    }

    public function test_smtp_delivery_failure_does_not_claim_the_installation(): void
    {
        Mail::fake();
        Notification::fake();
        $this->mock(SmtpBootstrapConfiguration::class)
            ->shouldReceive('testAndStoreBootstrapConfiguration')
            ->once()
            ->andThrow(ValidationException::withMessages([
                'smtp_host' => 'FieldOps could not send the SMTP test email.',
            ]));

        $this->post(route('installation.bootstrap.smtp-test'), $this->smtpTestPayload())
            ->assertSessionHasErrors('smtp_host')
            ->assertSessionMissing('_old_input.smtp_username')
            ->assertSessionMissing('_old_input.smtp_password');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('mail_transport_configurations', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_saved_smtp_configuration_can_be_retested_for_a_changed_admin_email_without_credentials(): void
    {
        Mail::fake();

        $this->post(route('installation.bootstrap.smtp-test'), $this->smtpTestPayload())
            ->assertRedirect(route('installation.bootstrap.create'));

        $this->post(route('installation.bootstrap.smtp-test'), [
            'email' => 'replacement.admin@example.test',
            'use_saved_configuration' => true,
        ])->assertRedirect(route('installation.bootstrap.create'));

        $configuration = MailTransportConfiguration::query()->sole();
        $this->assertSame('replacement.admin@example.test', $configuration->tested_recipient);
        Mail::assertSentTimes(BootstrapSmtpTestMail::class, 2);
    }

    public function test_failed_retest_of_saved_configuration_clears_verification_and_blocks_admin(): void
    {
        Mail::fake();
        Notification::fake();

        $this->post(route('installation.bootstrap.smtp-test'), $this->smtpTestPayload())
            ->assertRedirect(route('installation.bootstrap.create'));

        $configuration = MailTransportConfiguration::query()->sole();
        $this->assertNotNull($configuration->tested_at);

        config(['services.smtp_bootstrap_diagnostics' => true]);

        $transport = new class(['host' => 'smtp.example.test', 'port' => 587, 'scheme' => 'smtp', 'require_tls' => true, 'username' => 'smtp-test-user', 'password' => 'smtp-test-password']) extends SmtpBootstrapDiagnosticTransport
        {
            protected function doSend(SentMessage $message): void
            {
                $this->recordCommand("AUTH LOGIN\r\n");

                throw new UnexpectedResponseException('535 5.7.3 Authentication unsuccessful', 535);
            }
        };

        $this->mock(SmtpBootstrapDiagnosticTransportFactory::class)
            ->shouldReceive('make')
            ->once()
            ->andReturn($transport);

        $this->post(route('installation.bootstrap.smtp-test'), [
            'email' => 'initial.admin@example.test',
            'use_saved_configuration' => true,
        ])->assertSessionHasErrors('smtp_host');

        $configuration->refresh();
        $this->assertNull($configuration->tested_at);
        $this->assertSame('initial.admin@example.test', $configuration->tested_recipient);
        $this->assertSame('smtp.example.test', $configuration->host);
        $this->assertSame('smtp-test-user', $configuration->username);
        $this->assertSame('smtp-test-password', $configuration->password);

        $this->get(route('installation.bootstrap.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/FirstAdminBootstrap')
                ->where('smtp_backup.host', 'smtp.example.test')
                ->where('smtp_backup.tested_at', null)
                ->where('smtp_backup.tested_recipient', 'initial.admin@example.test')
                ->missing('smtp_backup.username')
                ->missing('smtp_backup.password'));

        $this->post(route('installation.bootstrap.store'), $this->firstAdminPayload())
            ->assertSessionHasErrors('smtp_host');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_first_admin_cannot_be_created_until_smtp_is_tested_for_the_current_form_values(): void
    {
        Mail::fake();
        Notification::fake();

        $this->post(route('installation.bootstrap.store'), $this->firstAdminPayload())
            ->assertSessionHasErrors('smtp_host');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('mail_transport_configurations', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_diagnostic_smtp_failure_logs_only_stage_and_response_metadata(): void
    {
        Notification::fake();
        config(['services.smtp_bootstrap_diagnostics' => true]);

        $transport = new class(['host' => 'smtp.example.test', 'port' => 587, 'scheme' => 'smtp', 'require_tls' => true, 'username' => 'smtp-user-marker', 'password' => 'smtp-password-marker']) extends SmtpBootstrapDiagnosticTransport
        {
            protected function doSend(SentMessage $message): void
            {
                $this->recordCommand("AUTH LOGIN\r\n");
                throw new UnexpectedResponseException(
                    '535 5.7.3 Denied smtp-password-marker smtp-user-marker recipient-marker',
                    535,
                );
            }
        };

        $this->mock(SmtpBootstrapDiagnosticTransportFactory::class)
            ->shouldReceive('make')
            ->once()
            ->andReturn($transport);

        Log::shouldReceive('warning')
            ->once()
            ->with('First-Admin SMTP test delivery failed', [
                'failure_stage' => 'smtp_authentication',
                'smtp_response_code' => 535,
                'exception_type' => 'UnexpectedResponseException',
            ]);

        $this->post(route('installation.bootstrap.smtp-test'), $this->smtpTestPayload())
            ->assertSessionHasErrors('smtp_host');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('mail_transport_configurations', 0);
        Notification::assertNothingSent();
    }

    public function test_smtp_credentials_must_be_paired_and_are_not_flashed(): void
    {
        Mail::fake();
        Notification::fake();

        $this->from(route('installation.bootstrap.create'))
            ->post(route('installation.bootstrap.smtp-test'), [
                ...$this->smtpTestPayload(),
                'smtp_password' => '',
            ])
            ->assertSessionHasErrors('smtp_password')
            ->assertSessionMissing('_old_input.smtp_password')
            ->assertSessionMissing('_old_input.smtp_username');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('mail_transport_configurations', 0);
        Mail::assertNothingOutgoing();
    }

    /** @return array<string, mixed> */
    private function firstAdminPayload(
        string $name = 'Initial Admin',
        string $email = 'initial.admin@example.test',
    ): array {
        return [
            'name' => $name,
            'email' => $email,
            'password' => 'Valid-Password-2026!',
            'password_confirmation' => 'Valid-Password-2026!',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_security' => 'starttls',
            'smtp_from_address' => 'fieldops@example.test',
            'smtp_from_name' => 'FieldOps',
        ];
    }

    /** @return array<string, mixed> */
    private function smtpTestPayload(string $email = 'initial.admin@example.test'): array
    {
        return [
            'email' => $email,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_security' => 'starttls',
            'smtp_username' => 'smtp-test-user',
            'smtp_password' => 'smtp-test-password',
            'smtp_from_address' => 'fieldops@example.test',
            'smtp_from_name' => 'FieldOps',
        ];
    }

    public function test_existing_staff_user_prevents_first_admin_bootstrap(): void
    {
        User::factory()->admin()->create();

        $this->get(route('installation.bootstrap.create'))
            ->assertRedirect(route('login'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('canBootstrap', false));
    }
}
