<?php

namespace App\Services;

use App\Mail\BootstrapSmtpTestMail;
use App\Mail\SmtpBootstrapDiagnosticTransport;
use App\Models\InstallationClaim;
use App\Models\MailTransportConfiguration;
use App\Models\User;
use Illuminate\Mail\Mailer as IlluminateMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class SmtpBootstrapConfiguration
{
    public function __construct(
        private readonly SmtpBootstrapDiagnosticTransportFactory $diagnosticTransportFactory,
    ) {}

    /** @param array<string, mixed> $data */
    public function testDelivery(array $data): void
    {
        $diagnosticsEnabled = config('services.smtp_bootstrap_diagnostics', false) === true;
        $originalDefault = config('mail.default');
        $originalSmtp = config('mail.mailers.smtp');
        $originalFrom = config('mail.from');
        $diagnosticTransport = null;

        try {
            if ($diagnosticsEnabled) {
                $diagnosticTransport = $this->diagnosticTransportFactory->make($this->mailerConfiguration($data));
                $from = $this->fromConfiguration($data);
                $mailer = new IlluminateMailer('bootstrap-smtp-test', app('view'), $diagnosticTransport, app('events'));
                $mailer->alwaysFrom($from['address'], $from['name']);
                $mailer->to($data['email'])->send(new BootstrapSmtpTestMail);

                return;
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => $this->mailerConfiguration($data),
                'mail.from' => $this->fromConfiguration($data),
            ]);

            Mail::purge('smtp');
            Mail::mailer('smtp')
                ->to($data['email'])
                ->send(new BootstrapSmtpTestMail);
        } catch (Throwable $exception) {
            if ($diagnosticsEnabled) {
                $context = $diagnosticTransport?->diagnosticContext($exception)
                    ?? SmtpBootstrapDiagnosticTransport::failureContext($exception);

                Log::warning('First-Admin SMTP test delivery failed', $context);
            }

            throw ValidationException::withMessages([
                'smtp_host' => 'FieldOps could not send the SMTP test email. Check the host, port, security setting, and credentials.',
            ]);
        } finally {
            if (! $diagnosticsEnabled) {
                Mail::purge('smtp');
                config([
                    'mail.default' => $originalDefault,
                    'mail.mailers.smtp' => $originalSmtp,
                    'mail.from' => $originalFrom,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function testAndStoreBootstrapConfiguration(array $data): void
    {
        $email = mb_strtolower(trim($data['email']));

        if (filter_var($data['use_saved_configuration'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $configuration = $this->pendingBootstrapConfiguration();

            if ($configuration === null) {
                throw $this->bootstrapTestRequired();
            }

            try {
                $this->testDelivery($this->testDataFromConfiguration($configuration, $email));
            } catch (Throwable $exception) {
                $this->clearBootstrapVerification($configuration->getKey());

                throw $exception;
            }

            DB::transaction(function () use ($configuration, $email): void {
                $this->lockAvailableBootstrapClaim();

                $pending = MailTransportConfiguration::query()
                    ->whereKey($configuration->getKey())
                    ->whereNull('configured_by_user_id')
                    ->lockForUpdate()
                    ->first();

                if ($pending === null) {
                    throw $this->bootstrapTestRequired();
                }

                $pending->forceFill([
                    'tested_at' => now(),
                    'tested_recipient' => $email,
                ])->save();
            });

            return;
        }

        $this->testDelivery($data);

        DB::transaction(function () use ($data, $email): void {
            $this->lockAvailableBootstrapClaim();

            $configuration = MailTransportConfiguration::query()
                ->where('singleton_key', MailTransportConfiguration::PRIMARY)
                ->lockForUpdate()
                ->first();

            if ($configuration?->configured_by_user_id !== null) {
                abort(404);
            }

            $configuration ??= new MailTransportConfiguration;
            $configuration->fill([
                'singleton_key' => MailTransportConfiguration::PRIMARY,
                'host' => trim($data['smtp_host']),
                'port' => (int) $data['smtp_port'],
                'security' => $data['smtp_security'],
                'username' => filled($data['smtp_username'] ?? null) ? trim($data['smtp_username']) : null,
                'password' => filled($data['smtp_password'] ?? null) ? $data['smtp_password'] : null,
                'from_address' => mb_strtolower(trim($data['smtp_from_address'])),
                'from_name' => trim($data['smtp_from_name']),
                'configured_by_user_id' => null,
                'tested_at' => now(),
                'tested_recipient' => $email,
            ])->save();
        });
    }

    /** @return array{host: string, port: int, security: string, from_address: string, from_name: string, tested_recipient: string|null, tested_at: string|null}|null */
    public function bootstrapSummary(): ?array
    {
        $configuration = $this->pendingBootstrapConfiguration();

        if ($configuration === null) {
            return null;
        }

        return [
            'host' => $configuration->host,
            'port' => $configuration->port,
            'security' => $configuration->security,
            'from_address' => $configuration->from_address,
            'from_name' => $configuration->from_name,
            'tested_recipient' => $configuration->tested_recipient,
            'tested_at' => $configuration->tested_at?->toIso8601String(),
        ];
    }

    /**
     * Drop the verified marker after a failed re-test of saved settings. The
     * saved non-secret settings and encrypted credentials are retained so the
     * operator can re-test without re-entering them, but the first-Admin claim
     * is blocked until a test succeeds again.
     */
    private function clearBootstrapVerification(int $configurationId): void
    {
        DB::transaction(function () use ($configurationId): void {
            $configuration = MailTransportConfiguration::query()
                ->whereKey($configurationId)
                ->whereNull('configured_by_user_id')
                ->lockForUpdate()
                ->first();

            if ($configuration === null || $configuration->tested_at === null) {
                return;
            }

            $configuration->forceFill(['tested_at' => null])->save();
        });
    }

    /** @param array<string, mixed> $data */
    public function hasMatchingBootstrapTest(array $data): bool
    {
        $configuration = $this->pendingBootstrapConfiguration();

        return $configuration !== null
            && $configuration->tested_at !== null
            && $configuration->tested_recipient !== null
            && mb_strtolower(trim($data['email'])) === $configuration->tested_recipient
            && trim($data['smtp_host']) === $configuration->host
            && (int) $data['smtp_port'] === $configuration->port
            && $data['smtp_security'] === $configuration->security
            && mb_strtolower(trim($data['smtp_from_address'])) === $configuration->from_address
            && trim($data['smtp_from_name']) === $configuration->from_name;
    }

    /** @param array<string, mixed> $data */
    public function claimBootstrapConfiguration(array $data, User $actor): void
    {
        $configuration = MailTransportConfiguration::query()
            ->where('singleton_key', MailTransportConfiguration::PRIMARY)
            ->whereNull('configured_by_user_id')
            ->lockForUpdate()
            ->first();

        if ($configuration === null || ! $this->configurationMatchesBootstrapData($configuration, $data)) {
            throw $this->bootstrapTestRequired();
        }

        $configuration->forceFill(['configured_by_user_id' => $actor->getKey()])->save();
    }

    public function applyStored(): bool
    {
        if (! Schema::hasTable('mail_transport_configurations')) {
            return false;
        }

        $configuration = MailTransportConfiguration::query()
            ->where('singleton_key', MailTransportConfiguration::PRIMARY)
            ->whereNotNull('configured_by_user_id')
            ->first();

        if ($configuration === null) {
            return false;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => $this->mailerConfiguration([
                'smtp_host' => $configuration->host,
                'smtp_port' => $configuration->port,
                'smtp_security' => $configuration->security,
                'smtp_username' => $configuration->username,
                'smtp_password' => $configuration->password,
            ]),
            'mail.from' => $this->fromConfiguration([
                'smtp_from_address' => $configuration->from_address,
                'smtp_from_name' => $configuration->from_name,
            ]),
        ]);

        Mail::purge('smtp');

        return true;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mailerConfiguration(array $data): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => ($data['smtp_security'] ?? null) === 'ssl' ? 'smtps' : 'smtp',
            'require_tls' => ($data['smtp_security'] ?? 'starttls') !== 'none',
            'host' => $data['smtp_host'],
            'port' => (int) $data['smtp_port'],
            'username' => filled($data['smtp_username'] ?? null) ? $data['smtp_username'] : null,
            'password' => filled($data['smtp_password'] ?? null) ? $data['smtp_password'] : null,
            'verify_peer' => true,
            'timeout' => 15,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ];
    }

    /** @param array<string, mixed> $data
     * @return array{address: string, name: string}
     */
    private function fromConfiguration(array $data): array
    {
        return [
            'address' => $data['smtp_from_address'],
            'name' => $data['smtp_from_name'],
        ];
    }

    private function pendingBootstrapConfiguration(): ?MailTransportConfiguration
    {
        if (! Schema::hasTable('mail_transport_configurations')) {
            return null;
        }

        return MailTransportConfiguration::query()
            ->where('singleton_key', MailTransportConfiguration::PRIMARY)
            ->whereNull('configured_by_user_id')
            ->first();
    }

    /** @param array<string, mixed> $data */
    private function configurationMatchesBootstrapData(MailTransportConfiguration $configuration, array $data): bool
    {
        return $configuration->tested_at !== null
            && $configuration->tested_recipient !== null
            && mb_strtolower(trim($data['email'])) === $configuration->tested_recipient
            && trim($data['smtp_host']) === $configuration->host
            && (int) $data['smtp_port'] === $configuration->port
            && $data['smtp_security'] === $configuration->security
            && mb_strtolower(trim($data['smtp_from_address'])) === $configuration->from_address
            && trim($data['smtp_from_name']) === $configuration->from_name;
    }

    private function lockAvailableBootstrapClaim(): void
    {
        $claim = InstallationClaim::query()->whereKey(1)->lockForUpdate()->firstOrFail();

        if (! $claim->isAvailable()) {
            abort(404);
        }
    }

    private function bootstrapTestRequired(): ValidationException
    {
        return ValidationException::withMessages([
            'smtp_host' => 'Test and save SMTP settings for this email address before creating the first Admin.',
        ]);
    }

    /** @return array<string, mixed> */
    private function testDataFromConfiguration(MailTransportConfiguration $configuration, string $email): array
    {
        return [
            'email' => $email,
            'smtp_host' => $configuration->host,
            'smtp_port' => $configuration->port,
            'smtp_security' => $configuration->security,
            'smtp_username' => $configuration->username,
            'smtp_password' => $configuration->password,
            'smtp_from_address' => $configuration->from_address,
            'smtp_from_name' => $configuration->from_name,
        ];
    }
}
