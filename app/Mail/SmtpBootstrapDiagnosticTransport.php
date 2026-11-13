<?php

namespace App\Mail;

use ReflectionClass;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

class SmtpBootstrapDiagnosticTransport extends EsmtpTransport
{
    private string $currentFailureStage = 'connection';

    private ?int $lastSmtpResponseCode = null;

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(#[\SensitiveParameter] array $configuration)
    {
        $scheme = $configuration['scheme'] ?? 'smtp';

        parent::__construct(
            (string) $configuration['host'],
            (int) $configuration['port'],
            $scheme === 'smtps' ? true : null,
        );

        $this->setAutoTls($scheme !== 'smtps');
        $this->setRequireTls((bool) ($configuration['require_tls'] ?? false));

        if ($this->getStream() instanceof SocketStream) {
            $this->getStream()->setStreamOptions([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            if (isset($configuration['timeout'])) {
                $this->getStream()->setTimeout((float) $configuration['timeout']);
            }
        }

        if (filled($configuration['username'] ?? null)) {
            $this->setUsername((string) $configuration['username']);
        }

        if (filled($configuration['password'] ?? null)) {
            $this->setPassword((string) $configuration['password']);
        }

        if (filled($configuration['local_domain'] ?? null)) {
            $this->setLocalDomain((string) $configuration['local_domain']);
        }

    }

    public function executeCommand(string $command, array $codes): string
    {
        $this->recordCommand($command);

        try {
            return parent::executeCommand($command, $codes);
        } catch (UnexpectedResponseException $exception) {
            $code = $exception->getCode();

            if ($code >= 200 && $code <= 599) {
                $this->lastSmtpResponseCode = $code;
            }

            throw $exception;
        }
    }

    public function recordCommand(string $command): void
    {
        $this->currentFailureStage = $this->stageForCommand($command, $this->currentFailureStage);
    }

    public function failureStage(): string
    {
        return $this->currentFailureStage;
    }

    /** @return array{failure_stage: string, smtp_response_code: int|null, exception_type: string} */
    public function diagnosticContext(Throwable $exception): array
    {
        return self::failureContext($exception, $this->currentFailureStage, $this->lastSmtpResponseCode);
    }

    /** @return array{failure_stage: string, smtp_response_code: int|null, exception_type: string} */
    public static function failureContext(
        Throwable $exception,
        string $stage = 'transport_setup',
        ?int $capturedResponseCode = null,
    ): array {
        $reflection = new ReflectionClass($exception);

        while ($reflection->isAnonymous() && ($parent = $reflection->getParentClass()) !== false) {
            $reflection = $parent;
        }

        return [
            'failure_stage' => $stage,
            'smtp_response_code' => $capturedResponseCode ?? self::smtpResponseCode($exception),
            'exception_type' => $reflection->getShortName(),
        ];
    }

    private static function smtpResponseCode(Throwable $exception): ?int
    {
        for ($candidate = $exception; $candidate !== null; $candidate = $candidate->getPrevious()) {
            if (! $candidate instanceof UnexpectedResponseException) {
                continue;
            }

            $code = $candidate->getCode();

            if ($code >= 200 && $code <= 599) {
                return $code;
            }
        }

        return null;
    }

    private function stageForCommand(string $command, string $currentStage): string
    {
        $command = strtoupper(trim($command));

        return match (true) {
            str_starts_with($command, 'STARTTLS') => 'tls_negotiation',
            str_starts_with($command, 'EHLO '), str_starts_with($command, 'HELO ') => 'smtp_handshake',
            str_starts_with($command, 'AUTH ') => 'smtp_authentication',
            str_starts_with($command, 'MAIL FROM:') => 'sender_acceptance',
            str_starts_with($command, 'RCPT TO:') => 'recipient_acceptance',
            $command === 'DATA' => 'message_submission',
            $command === '.' => 'message_acceptance',
            $currentStage === 'smtp_authentication' => 'smtp_authentication',
            default => $currentStage,
        };
    }
}
