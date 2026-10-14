<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\ApplicationSetupState;
use App\Services\BwsSecretHelper;
use App\Services\MicrosoftGraphTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class MicrosoftGraphTokenProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
    }

    public function test_legacy_environment_client_credentials_are_not_used_without_a_verified_bws_connection(): void
    {
        config([
            'services.microsoft_graph.writes_enabled' => true,
            'services.microsoft_graph.tenant_id' => 'tenant-test',
            'services.microsoft_graph.client_id' => 'legacy-client-id',
            'services.microsoft_graph.client_secret' => 'legacy-client-secret',
        ]);
        Process::fake();
        Http::fake([]);
        Http::preventStrayRequests();

        try {
            app(MicrosoftGraphTokenProvider::class)->accessToken();
            $this->fail('Expected missing BWS configuration to stop token acquisition.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_configuration_incomplete', $exception->reason);
            $this->assertStringNotContainsString('legacy-client-secret', $exception->getMessage());
        }

        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_bws_credential_read_failure_stops_before_graph_authentication(): void
    {
        config([
            'services.microsoft_graph.writes_enabled' => true,
            'services.microsoft_graph.tenant_id' => 'tenant-test',
            'services.microsoft_graph.client_id' => 'legacy-client-id',
            'services.microsoft_graph.client_secret' => 'legacy-client-secret',
        ]);
        $this->fakeMicrosoftGraphBwsCredentials();
        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"project_access_failed","results":[]}',
            errorOutput: 'sensitive helper diagnostic',
            exitCode: 1,
        ));
        Http::fake([]);
        Http::preventStrayRequests();

        try {
            app(MicrosoftGraphTokenProvider::class)->accessToken();
            $this->fail('Expected a BWS read failure to stop token acquisition.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_configuration_incomplete', $exception->reason);
            $this->assertSame('graph_configuration_incomplete', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive helper diagnostic', $exception->getMessage());
            $this->assertStringNotContainsString('legacy-client-secret', $exception->getMessage());
        }

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [BwsSecretHelper::HELPER_COMMAND]);
        Http::assertNothingSent();
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'login.microsoftonline.com'));
    }

    public function test_cached_graph_access_token_avoids_re_resolving_bws_secret_values(): void
    {
        config([
            'services.microsoft_graph.writes_enabled' => true,
            'services.microsoft_graph.tenant_id' => 'tenant-test',
        ]);
        $this->fakeMicrosoftGraphBwsCredentials();
        $bwsReadCount = 0;
        Process::fake(function (PendingProcess $process) use (&$bwsReadCount) {
            $bwsReadCount++;

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'resolved_secrets' => [
                    'MICROSOFT_GRAPH_CLIENT_ID' => 'test-graph-client-id',
                    'MICROSOFT_GRAPH_CLIENT_SECRET' => 'test-graph-client-secret',
                ],
            ], JSON_THROW_ON_ERROR));
        });
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-access-token',
                'expires_in' => 3600,
            ]),
        ]);
        $provider = app(MicrosoftGraphTokenProvider::class);

        $firstToken = $provider->accessToken();
        $secondToken = $provider->accessToken();

        $this->assertSame('mock-graph-access-token', $firstToken);
        $this->assertSame($firstToken, $secondToken);
        $this->assertSame(1, $bwsReadCount);
        Http::assertSentCount(1);
    }
}
