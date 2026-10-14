<?php

namespace App\Services;

use App\Mail\BootstrapSmtpTestMail;
use App\Models\MailTransportConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class SmtpBootstrapConfiguration
{
    /** @param array<string, mixed> $data */
    public function testDelivery(array $data): void
    {
        $originalDefault = config('mail.default');
        $originalSmtp = config('mail.mailers.smtp');
        $originalFrom = config('mail.from');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => $this->mailerConfiguration($data),
            'mail.from' => $this->fromConfiguration($data),
        ]);

        Mail::purge('smtp');

        try {
            Mail::mailer('smtp')
                ->to($data['email'])
                ->send(new BootstrapSmtpTestMail);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'smtp_host' => 'FieldOps could not send the SMTP test email. Check the host, port, security setting, and credentials.',
            ]);
        } finally {
            Mail::purge('smtp');
            config([
                'mail.default' => $originalDefault,
                'mail.mailers.smtp' => $originalSmtp,
                'mail.from' => $originalFrom,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    public function store(array $data, User $actor): MailTransportConfiguration
    {
        return MailTransportConfiguration::query()->create([
            'singleton_key' => MailTransportConfiguration::PRIMARY,
            'host' => trim($data['smtp_host']),
            'port' => (int) $data['smtp_port'],
            'security' => $data['smtp_security'],
            'username' => filled($data['smtp_username'] ?? null) ? trim($data['smtp_username']) : null,
            'password' => filled($data['smtp_password'] ?? null) ? $data['smtp_password'] : null,
            'from_address' => mb_strtolower(trim($data['smtp_from_address'])),
            'from_name' => trim($data['smtp_from_name']),
            'configured_by_user_id' => $actor->getKey(),
            'tested_at' => now(),
        ]);
    }

    public function applyStored(): bool
    {
        if (! Schema::hasTable('mail_transport_configurations')) {
            return false;
        }

        $configuration = MailTransportConfiguration::query()
            ->where('singleton_key', MailTransportConfiguration::PRIMARY)
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
}
