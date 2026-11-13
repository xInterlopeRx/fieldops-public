<?php

namespace Tests\Unit\Mail;

use App\Mail\SmtpBootstrapDiagnosticTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

class SmtpBootstrapDiagnosticTransportTest extends TestCase
{
    public function test_stage_tracks_smtp_commands_without_retaining_command_payloads(): void
    {
        $transport = new SmtpBootstrapDiagnosticTransport([
            'host' => 'smtp.example.test',
            'port' => 587,
            'scheme' => 'smtp',
            'require_tls' => true,
            'username' => 'test-user',
            'password' => 'test-password',
        ]);

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ], $stream->getStreamOptions());

        $transport->recordCommand("EHLO test.example\r\n");
        $this->assertSame('smtp_handshake', $transport->failureStage());

        $transport->recordCommand("STARTTLS\r\n");
        $this->assertSame('tls_negotiation', $transport->failureStage());

        $transport->recordCommand("AUTH LOGIN\r\n");
        $this->assertSame('smtp_authentication', $transport->failureStage());
        $transport->recordCommand(base64_encode('test-user')."\r\n");
        $transport->recordCommand(base64_encode('test-password')."\r\n");
        $this->assertSame('smtp_authentication', $transport->failureStage());

        $transport->recordCommand("MAIL FROM:<sender@example.test>\r\n");
        $this->assertSame('sender_acceptance', $transport->failureStage());
        $transport->recordCommand("RCPT TO:<recipient@example.test>\r\n");
        $this->assertSame('recipient_acceptance', $transport->failureStage());
        $transport->recordCommand("DATA\r\n");
        $this->assertSame('message_submission', $transport->failureStage());
        $transport->recordCommand("\r\n.\r\n");
        $this->assertSame('message_acceptance', $transport->failureStage());
    }

    public function test_failure_context_contains_only_sanitized_smtp_metadata(): void
    {
        $exception = new UnexpectedResponseException(
            '535 rejected test-password-marker test-user-marker recipient-marker',
            535,
        );

        $context = SmtpBootstrapDiagnosticTransport::failureContext($exception, 'smtp_authentication');

        $this->assertSame([
            'failure_stage' => 'smtp_authentication',
            'smtp_response_code' => 535,
            'exception_type' => 'UnexpectedResponseException',
        ], $context);
        $this->assertStringNotContainsString('marker', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_non_smtp_exception_does_not_report_its_code_as_a_server_response(): void
    {
        $exception = new class('Contains test-password-marker', 535) extends \RuntimeException {};

        $context = SmtpBootstrapDiagnosticTransport::failureContext($exception, 'transport_setup');

        $this->assertSame([
            'failure_stage' => 'transport_setup',
            'smtp_response_code' => null,
            'exception_type' => 'RuntimeException',
        ], $context);
        $this->assertStringNotContainsString('marker', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_synthesized_transport_code_is_not_reported_as_an_smtp_response(): void
    {
        $exception = new TransportException('No compatible SMTP authenticator', 504);

        $context = SmtpBootstrapDiagnosticTransport::failureContext($exception, 'smtp_handshake');

        $this->assertNull($context['smtp_response_code']);
    }
}
