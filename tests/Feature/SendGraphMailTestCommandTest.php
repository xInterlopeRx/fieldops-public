<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SendGraphMailTestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
    }

    public function test_mail_test_command_cannot_send_outside_local_environment(): void
    {
        $this->app['env'] = 'production';
        $this->configureMailTest();
        Http::fake([]);
        Http::preventStrayRequests();

        $output = new BufferedOutput;
        $exitCode = $this->artisanCall($output);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('APP_ENV=local', $output->fetch());
        Http::assertNothingSent();
    }

    public function test_mail_test_command_requires_an_allowlisted_recipient_and_exact_confirmation(): void
    {
        $this->app['env'] = 'local';
        $this->configureMailTest();
        Http::fake([]);
        Http::preventStrayRequests();

        $unlistedOutput = new BufferedOutput;
        $unlistedExitCode = Artisan::call('graph:mail:test', [
            'recipient' => 'other@example.test',
            '--confirm-recipient' => 'other@example.test',
        ], $unlistedOutput);
        $confirmationOutput = new BufferedOutput;
        $confirmationExitCode = Artisan::call('graph:mail:test', [
            'recipient' => 'developer@example.test',
            '--confirm-recipient' => 'wrong@example.test',
        ], $confirmationOutput);

        $this->assertSame(Command::FAILURE, $unlistedExitCode);
        $this->assertStringContainsString('not in MICROSOFT_GRAPH_TEST_RECIPIENTS', $unlistedOutput->fetch());
        $this->assertSame(Command::FAILURE, $confirmationExitCode);
        $this->assertStringContainsString('Repeat the exact recipient', $confirmationOutput->fetch());
        Http::assertNothingSent();
    }

    public function test_mail_test_command_sends_one_minimal_message_to_an_allowlisted_recipient(): void
    {
        $this->app['env'] = 'local';
        $this->configureMailTest();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/notification-mailbox-id/sendMail' => Http::response([], 202),
        ]);

        $output = new BufferedOutput;
        $exitCode = $this->artisanCall($output);
        $message = $output->fetch();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('accepted one test email', $message);
        $this->assertStringContainsString('does not confirm delivery', $message);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/users/notification-mailbox-id/sendMail')
            && $request['message']['toRecipients'][0]['emailAddress']['address'] === 'developer@example.test'
            && str_starts_with($request['message']['subject'], 'FieldOps Graph delivery test ')
            && $request['message']['body']['contentType'] === 'text'
            && str_contains($request['message']['body']['content'], 'No customer or Job data is included.')
            && ! isset($request['message']['attachments'])
            && $request['saveToSentItems'] === false);
        Http::assertSentCount(2);
    }

    private function configureMailTest(): void
    {
        Cache::store('array')->flush();
        config([
            'services.microsoft_graph.writes_enabled' => true,
            'services.microsoft_graph.tenant_id' => 'tenant-test',
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
            'services.microsoft_graph.test_recipients' => ['developer@example.test'],
        ]);
        $this->fakeMicrosoftGraphBwsCredentials();
    }

    private function artisanCall(BufferedOutput $output): int
    {
        return Artisan::call('graph:mail:test', [
            'recipient' => 'developer@example.test',
            '--confirm-recipient' => 'developer@example.test',
        ], $output);
    }
}
