<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\ApplicationSetupState;
use App\Services\MicrosoftGraphApiClient;
use App\Services\MicrosoftGraphSharePointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class MicrosoftGraphSharePointDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
    }

    public function test_read_only_validation_commands_discover_site_drives_and_document_libraries(): void
    {
        $this->configureSharePointDiscovery();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [
                [
                    'id' => 'drive-documents',
                    'name' => 'Shared Documents',
                    'driveType' => 'documentLibrary',
                    'webUrl' => 'https://example.sharepoint.com/sites/work-schedules/Shared%20Documents',
                    'sharePointIds' => ['listId' => 'list-documents'],
                ],
                [
                    'id' => 'drive-assets',
                    'name' => 'Team Assets',
                    'driveType' => 'documentLibrary',
                    'webUrl' => 'https://example.sharepoint.com/sites/work-schedules/Assets',
                    'sharePointIds' => ['listId' => 'list-assets'],
                ],
            ]]),
            'graph.microsoft.com/v1.0/sites/site-test/lists*' => Http::response(['value' => [
                [
                    'id' => 'list-documents',
                    'name' => 'Shared Documents',
                    'displayName' => 'Documents',
                    'webUrl' => 'https://example.sharepoint.com/sites/work-schedules/Shared%20Documents',
                    'list' => ['template' => 'documentLibrary'],
                ],
                [
                    'id' => 'list-tasks',
                    'name' => 'Tasks',
                    'displayName' => 'Tasks',
                    'webUrl' => 'https://example.sharepoint.com/sites/work-schedules/Lists/Tasks',
                    'list' => ['template' => 'genericList'],
                ],
            ]]),
            'graph.microsoft.com/v1.0/sites/site-test*' => Http::response([
                'id' => 'site-test',
                'displayName' => 'Work Schedules',
                'webUrl' => 'https://example.sharepoint.com/sites/work-schedules',
                'siteCollection' => ['hostname' => 'example.sharepoint.com'],
            ]),
        ]);

        $siteOutput = new BufferedOutput;
        $siteExitCode = Artisan::call('graph:sharepoint:discover-site', [], $siteOutput);
        $siteContent = $siteOutput->fetch();
        $this->assertSame(0, $siteExitCode, $siteContent);
        $this->assertStringContainsString('Work Schedules', $siteContent);
        $this->assertStringContainsString('example.sharepoint.com', $siteContent);

        $driveOutput = new BufferedOutput;
        $driveExitCode = Artisan::call('graph:sharepoint:discover-drives', [], $driveOutput);
        $driveContent = $driveOutput->fetch();
        $this->assertSame(0, $driveExitCode, $driveContent);
        $this->assertStringContainsString('Shared Documents', $driveContent);
        $this->assertStringContainsString('Team Assets', $driveContent);

        $libraryOutput = new BufferedOutput;
        $libraryExitCode = Artisan::call('graph:sharepoint:discover-libraries', [], $libraryOutput);
        $libraryContent = $libraryOutput->fetch();
        $this->assertSame(0, $libraryExitCode, $libraryContent);
        $this->assertStringContainsString('Documents', $libraryContent);
        $this->assertStringContainsString('drive-documents', $libraryContent);
        $this->assertStringNotContainsString('Tasks', $libraryContent);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_starts_with(
            $request->url(),
            'https://graph.microsoft.com/',
        ) && $request->method() !== 'GET');
    }

    public function test_sharepoint_validation_commands_are_disabled_by_default(): void
    {
        config(['services.microsoft_graph.sharepoint_enabled' => false]);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:sharepoint:discover-site', [], $output);
        $content = $output->fetch();
        $this->assertSame(1, $exitCode, $content);
        $this->assertStringContainsString('graph_sharepoint_discovery_disabled', $content);
    }

    public function test_local_site_discovery_logs_safe_graph_diagnostics_and_returns_the_graph_error(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test*' => Http::response([
                'error' => [
                    'code' => 'invalidRequest',
                    'message' => 'The access token mock-sharepoint-token was rejected.',
                ],
            ], 400),
        ]);
        Log::shouldReceive('debug')->twice()->withArgs(function (string $message, array $context): bool {
            if ($message === 'Microsoft Graph token acquired') {
                $this->assertTrue($context['token_acquired']);
                $this->assertSame('aad', $context['token_source']);
                $this->assertNotNull($context['token_expiration']);
                $this->assertNull($context['audience_claim']);
                $this->assertSame('tenant-test', $context['tenant_id']);
                $this->assertNull($context['aud']);
                $this->assertNull($context['tid']);
                $this->assertNull($context['appid']);
                $this->assertSame([], $context['roles']);
                $this->assertNull($context['scp']);
                $this->assertStringNotContainsString('mock-sharepoint-token', json_encode($context));

                return true;
            }

            $this->assertSame('Microsoft Graph HTTP request', $message);
            $this->assertSame([
                'failure_category',
                'method',
                'request_url',
                'http_status',
                'graph_error_code',
                'graph_error_message',
                'correlation_id',
                'request_id',
                'jwt_claims',
            ], array_keys($context));
            $this->assertSame('graph_request_failure', $context['failure_category']);
            $this->assertSame('GET', $context['method']);
            $this->assertStringContainsString('graph.microsoft.com/v1.0/sites/site-test', $context['request_url']);
            $this->assertStringContainsString('displayName', $context['request_url']);
            $this->assertSame(400, $context['http_status']);
            $this->assertSame('invalidRequest', $context['graph_error_code']);
            $this->assertSame('The access token [redacted] was rejected.', $context['graph_error_message']);
            $this->assertNotEmpty($context['correlation_id']);
            $this->assertNull($context['request_id']);
            $this->assertSame([
                'aud' => null,
                'tid' => null,
                'appid' => null,
                'roles' => [],
                'scp' => null,
            ], $context['jwt_claims']);
            $this->assertStringNotContainsString('mock-sharepoint-token', json_encode($context));
            $this->assertStringNotContainsString('test-graph-client-secret', json_encode($context));

            return true;
        });

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:sharepoint:discover-site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('HTTP 400', $content);
        $this->assertStringContainsString('invalidRequest', $content);
        $this->assertStringContainsString('The access token [redacted] was rejected.', $content);
        $this->assertStringNotContainsString('mock-sharepoint-token', $content);
    }

    public function test_local_successful_token_and_graph_request_log_metadata_without_tokens(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();

        $expiration = 1_900_000_000;
        $accessToken = $this->jwtToken([
            'aud' => 'https://graph.microsoft.com',
            'tid' => 'tenant-claim-test',
            'appid' => 'app-claim-test',
            'roles' => ['Sites.Selected'],
            'scp' => 'Files.Read',
            'exp' => $expiration,
        ]);

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => $accessToken,
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test*' => Http::response(
                ['id' => 'site-test', 'displayName' => 'Work Schedules'],
                200,
                ['client-request-id' => 'response-correlation-test', 'request-id' => 'graph-request-test'],
            ),
        ]);

        Log::shouldReceive('debug')->twice()->withArgs(function (string $message, array $context) use ($accessToken, $expiration): bool {
            if ($message === 'Microsoft Graph token acquired') {
                $this->assertSame([
                    'token_acquired',
                    'token_source',
                    'token_expiration',
                    'audience_claim',
                    'tenant_id',
                    'token_tenant_id',
                    'aud',
                    'tid',
                    'appid',
                    'roles',
                    'scp',
                ], array_keys($context));
                $this->assertTrue($context['token_acquired']);
                $this->assertSame('aad', $context['token_source']);
                $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $expiration), $context['token_expiration']);
                $this->assertSame('https://graph.microsoft.com', $context['audience_claim']);
                $this->assertSame('tenant-test', $context['tenant_id']);
                $this->assertSame('tenant-claim-test', $context['token_tenant_id']);
                $this->assertSame('https://graph.microsoft.com', $context['aud']);
                $this->assertSame('tenant-claim-test', $context['tid']);
                $this->assertSame('app-claim-test', $context['appid']);
                $this->assertSame(['Sites.Selected'], $context['roles']);
                $this->assertSame('Files.Read', $context['scp']);
                $this->assertStringNotContainsString($accessToken, json_encode($context));

                return true;
            }

            $this->assertSame('Microsoft Graph HTTP request', $message);
            $this->assertSame([
                'failure_category',
                'method',
                'request_url',
                'http_status',
                'graph_error_code',
                'graph_error_message',
                'correlation_id',
                'request_id',
            ], array_keys($context));
            $this->assertNull($context['failure_category']);
            $this->assertSame('GET', $context['method']);
            $this->assertStringContainsString('https://graph.microsoft.com/v1.0/sites/site-test', $context['request_url']);
            $this->assertSame(200, $context['http_status']);
            $this->assertNull($context['graph_error_code']);
            $this->assertNull($context['graph_error_message']);
            $this->assertSame('response-correlation-test', $context['correlation_id']);
            $this->assertSame('graph-request-test', $context['request_id']);
            $this->assertStringNotContainsString($accessToken, json_encode($context));
            $this->assertArrayNotHasKey('authorization', array_change_key_case($context));

            return true;
        });

        $response = app(MicrosoftGraphApiClient::class)->getJson('sites/site-test', ['$select' => 'id,displayName']);

        $this->assertSame('site-test', $response['id']);
    }

    public function test_local_token_failure_logs_only_aad_error_details_and_returns_them(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Client secret test-graph-client-secret was rejected.',
            ], 401),
        ]);
        Log::shouldReceive('debug')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('Microsoft Graph token acquisition failed', $message);
            $this->assertSame([
                'failure_category',
                'token_acquired',
                'token_source',
                'token_expiration',
                'audience_claim',
                'aud',
                'tid',
                'appid',
                'roles',
                'scp',
                'tenant_id',
                'token_tenant_id',
                'http_status',
                'aad_error_code',
                'aad_error_description',
                'failure_reason',
            ], array_keys($context));
            $this->assertSame('authentication_failure', $context['failure_category']);
            $this->assertFalse($context['token_acquired']);
            $this->assertNull($context['token_expiration']);
            $this->assertNull($context['audience_claim']);
            $this->assertNull($context['aud']);
            $this->assertNull($context['tid']);
            $this->assertNull($context['appid']);
            $this->assertSame([], $context['roles']);
            $this->assertNull($context['scp']);
            $this->assertSame('tenant-test', $context['tenant_id']);
            $this->assertSame(401, $context['http_status']);
            $this->assertSame('invalid_client', $context['aad_error_code']);
            $this->assertSame('Client secret [redacted] was rejected.', $context['aad_error_description']);
            $this->assertStringNotContainsString('mock-sharepoint-token', json_encode($context));
            $this->assertStringNotContainsString('test-graph-client-secret', json_encode($context));

            return true;
        });

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:sharepoint:discover-site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Authentication failure — AAD HTTP 401 — invalid_client', $content);
        $this->assertStringContainsString('Client secret [redacted] was rejected.', $content);
        $this->assertStringNotContainsString('test-graph-client-secret', $content);
    }

    public function test_production_token_failure_keeps_aad_details_sanitized(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->fakeMicrosoftGraphBwsCredentials('production');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'Sensitive AAD detail must not be returned.',
            ], 401),
        ]);
        Log::shouldReceive('debug')->never();

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:sharepoint:discover-site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('graph_auth_failed', $content);
        $this->assertStringNotContainsString('invalid_client', $content);
        $this->assertStringNotContainsString('Sensitive AAD detail', $content);
    }

    public function test_local_graph_debug_site_command_outputs_endpoint_auth_mode_and_response_details(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();
        $accessToken = $this->jwtToken([
            'aud' => 'https://graph.microsoft.com',
            'tid' => 'tenant-claim-test',
            'appid' => 'app-claim-test',
            'roles' => ['Sites.Selected'],
            'scp' => 'Files.Read',
            'exp' => 1_900_000_000,
        ]);
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => $accessToken,
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test*' => Http::response([
                'error' => [
                    'code' => 'accessDenied',
                    'message' => 'The caller is not authorized for this Site.',
                ],
            ], 403, ['client-request-id' => 'correlation-debug-test', 'request-id' => 'request-debug-test']),
        ]);
        Log::shouldReceive('debug')->twice()->withArgs(function (string $message, array $context) use ($accessToken): bool {
            $this->assertStringNotContainsString($accessToken, json_encode($context));

            if ($message === 'Microsoft Graph HTTP request') {
                $this->assertSame([
                    'aud' => 'https://graph.microsoft.com',
                    'tid' => 'tenant-claim-test',
                    'appid' => 'app-claim-test',
                    'roles' => ['Sites.Selected'],
                    'scp' => 'Files.Read',
                ], $context['jwt_claims']);
            }

            return true;
        });

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:debug:site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Site ID: site-test', $content);
        $this->assertStringContainsString('Endpoint URL: https://graph.microsoft.com/v1.0/sites/site-test?', $content);
        $this->assertStringContainsString('Auth mode: app-only client credentials (Graph /.default)', $content);
        $this->assertStringContainsString('HTTP status: 403', $content);
        $this->assertStringContainsString('Failure category: authorization_failure', $content);
        $this->assertStringContainsString('Graph error code: accessDenied', $content);
        $this->assertStringContainsString('The caller is not authorized for this Site.', $content);
        $this->assertStringContainsString('Correlation ID: correlation-debug-test', $content);
        $this->assertStringContainsString('Request ID: request-debug-test', $content);
        $this->assertStringContainsString('JWT claim aud: "https://graph.microsoft.com"', $content);
        $this->assertStringContainsString('JWT claim tid: "tenant-claim-test"', $content);
        $this->assertStringContainsString('JWT claim appid: "app-claim-test"', $content);
        $this->assertStringContainsString('JWT claim roles: ["Sites.Selected"]', $content);
        $this->assertStringContainsString('JWT claim scp: "Files.Read"', $content);
        $this->assertStringNotContainsString($accessToken, $content);
    }

    public function test_local_graph_debug_site_command_displays_successful_site_metadata(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test*' => Http::response([
                'id' => 'site-test',
                'displayName' => 'Work Schedules',
                'webUrl' => 'https://example.sharepoint.com/sites/work-schedules',
                'siteCollection' => ['hostname' => 'example.sharepoint.com'],
            ]),
        ]);
        Log::shouldReceive('debug')->twice();

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:debug:site', [], $output);
        $content = $output->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('HTTP status: 200', $content);
        $this->assertStringContainsString('Site name: Work Schedules', $content);
        $this->assertStringContainsString('https://example.sharepoint.com/sites/work-schedules', $content);
        $this->assertStringContainsString('example.sharepoint.com', $content);
        $this->assertStringNotContainsString('mock-sharepoint-token', $content);
    }

    public function test_graph_debug_site_command_labels_aad_authentication_failures_correctly(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'The configured client credential was rejected.',
            ], 401),
        ]);
        Log::shouldReceive('debug')->once();

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:debug:site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('AAD error code: invalid_client', $content);
        $this->assertStringContainsString('AAD error description: The configured client credential was rejected.', $content);
        $this->assertStringNotContainsString('Graph error code:', $content);
    }

    public function test_graph_debug_site_command_refuses_to_run_outside_local(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->fakeMicrosoftGraphBwsCredentials('production');
        Http::preventStrayRequests();

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:debug:site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('available only in the local environment', $content);
    }

    #[DataProvider('graphFailureCases')]
    public function test_local_site_discovery_classifies_graph_failures(
        int $status,
        string $errorCode,
        string $errorMessage,
        string $failureCategory,
    ): void {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();

        $graphError = ['error' => ['code' => $errorCode, 'message' => $errorMessage]];
        $graphResponse = $status === 401
            ? Http::sequence()->push($graphError, $status)->push($graphError, $status)
            : Http::response($graphError, $status);
        $accessToken = $this->jwtToken([
            'aud' => 'https://graph.microsoft.com',
            'tid' => 'tenant-debug-test',
            'appid' => 'app-debug-test',
            'roles' => ['Sites.Selected'],
            'scp' => 'Files.Read',
            'exp' => 1_900_000_000,
        ]);

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::sequence()
                ->push(['access_token' => $accessToken, 'expires_in' => 3600])
                ->push(['access_token' => $accessToken, 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/sites/site-test*' => $graphResponse,
        ]);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:sharepoint:discover-site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($failureCategory, $content);
        $this->assertStringContainsString('HTTP '.$status, $content);
        $this->assertStringContainsString($errorCode, $content);
        $this->assertStringContainsString($errorMessage, $content);
        $this->assertStringContainsString('JWT claims', $content);
        $this->assertStringContainsString('"appid":"app-debug-test"', $content);
        $this->assertStringContainsString('"roles":["Sites.Selected"]', $content);
        $this->assertStringContainsString('"scp":"Files.Read"', $content);
        $this->assertStringNotContainsString($accessToken, $content);
    }

    public static function graphFailureCases(): array
    {
        return [
            'authentication failure' => [401, 'InvalidAuthenticationToken', 'The access token is invalid.', 'Authentication failure'],
            'authorization failure' => [403, 'accessDenied', 'The caller is not authorized.', 'Authorization failure'],
            'resource not found' => [404, 'itemNotFound', 'The site could not be found.', 'Resource not found'],
            'service failure' => [503, 'serviceUnavailable', 'The service is temporarily unavailable.', 'Graph service failure'],
        ];
    }

    public function test_production_site_discovery_keeps_graph_error_details_sanitized(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->fakeMicrosoftGraphBwsCredentials('production');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test*' => Http::response([
                'error' => [
                    'code' => 'invalidRequest',
                    'message' => 'Sensitive upstream detail must not be returned.',
                ],
            ], 400),
        ]);
        Log::shouldReceive('debug')->never();

        $output = new BufferedOutput;
        $exitCode = Artisan::call('graph:sharepoint:discover-site', [], $output);
        $content = $output->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('graph_request_failed', $content);
        $this->assertStringNotContainsString('invalidRequest', $content);
        $this->assertStringNotContainsString('Sensitive upstream detail', $content);
        $this->assertStringNotContainsString('mock-sharepoint-token', $content);
    }

    public function test_discovery_rejects_an_invalid_site_id_before_making_a_graph_request(): void
    {
        $this->configureSharePointDiscovery();
        Http::preventStrayRequests();

        $this->expectException(MicrosoftGraphApiException::class);
        $this->expectExceptionMessage('graph_sharepoint_site_id_invalid_or_missing');

        app(MicrosoftGraphSharePointService::class)->discoverSite('../another-site');
    }

    public function test_api_client_rejects_external_graph_continuation_links(): void
    {
        $this->expectException(MicrosoftGraphApiException::class);
        $this->expectExceptionMessage('graph_invalid_next_link');

        app(MicrosoftGraphApiClient::class)
            ->getJsonNextPage('https://attacker.example/v1.0/sites/site-test/drives?$skiptoken=bad');
    }

    public function test_drive_discovery_follows_graph_paging_links_with_a_bounded_result_set(): void
    {
        $this->configureSharePointDiscovery();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::sequence()
                ->push([
                    'value' => [[
                        'id' => 'drive-one',
                        'name' => 'Documents',
                        'sharePointIds' => ['listId' => 'list-one'],
                    ]],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/sites/site-test/drives?$skiptoken=page-2',
                ])
                ->push(['value' => [[
                    'id' => 'drive-two',
                    'name' => 'Assets',
                    'sharePointIds' => ['listId' => 'list-two'],
                ]]]),
        ]);

        $drives = app(MicrosoftGraphSharePointService::class)->discoverDrives('site-test');

        $this->assertSame(['Assets', 'Documents'], array_column($drives, 'name'));
        $this->assertSame(['drive-two', 'drive-one'], array_column($drives, 'id'));
    }

    public function test_drive_discovery_rejects_a_collection_over_the_item_limit(): void
    {
        $this->configureSharePointDiscovery();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response([
                'value' => array_fill(0, 10001, ['id' => 'drive-one', 'name' => 'Documents']),
            ]),
        ]);

        $this->expectException(MicrosoftGraphApiException::class);
        $this->expectExceptionMessage('graph_sharepoint_page_limit_exceeded');

        app(MicrosoftGraphSharePointService::class)->discoverDrives();
    }

    public function test_drive_discovery_rejects_malformed_collection_items(): void
    {
        $this->configureSharePointDiscovery();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response([
                'value' => ['not-an-object'],
            ]),
        ]);

        $this->expectException(MicrosoftGraphApiException::class);
        $this->expectExceptionMessage('graph_sharepoint_invalid_collection_response');

        app(MicrosoftGraphSharePointService::class)->discoverDrives();
    }

    public function test_api_client_accepts_a_graph_continuation_link(): void
    {
        $this->configureSharePointDiscovery();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response([
                'value' => [['id' => 'drive-next-page']],
            ]),
        ]);

        $response = app(MicrosoftGraphApiClient::class)->getJsonNextPage(
            'https://graph.microsoft.com/v1.0/sites/site-test/drives?$skiptoken=page-2',
        );

        $this->assertSame('drive-next-page', $response['value'][0]['id']);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'skiptoken=page-2'));
    }

    public function test_local_graph_diagnostics_redact_credential_values_from_request_urls(): void
    {
        $this->configureSharePointDiscovery();
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->fakeMicrosoftGraphBwsCredentials('local');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response([
                'error' => ['code' => 'invalidRequest', 'message' => 'Bad request.'],
            ], 400),
        ]);

        try {
            app(MicrosoftGraphApiClient::class)->getJsonNextPage(
                'https://graph.microsoft.com/v1.0/sites/site-test/drives?access_token=access-value&refresh_token=refresh-value&client_secret=secret-value',
            );
            $this->fail('Expected the mocked Graph request to fail.');
        } catch (MicrosoftGraphApiException $exception) {
            $context = $exception->debugContext;

            $this->assertNotNull($context);
            $this->assertStringContainsString('access_token=[redacted]', $context['request_url']);
            $this->assertStringContainsString('refresh_token=[redacted]', $context['request_url']);
            $this->assertStringContainsString('client_secret=[redacted]', $context['request_url']);
            $this->assertStringNotContainsString('access-value', json_encode($context));
            $this->assertStringNotContainsString('refresh-value', json_encode($context));
            $this->assertStringNotContainsString('secret-value', json_encode($context));
            $this->assertStringNotContainsString('mock-sharepoint-token', json_encode($context));
        }
    }

    /** @param array<string, mixed> $claims */
    private function jwtToken(array $claims): string
    {
        $encodedClaims = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return 'header.'.$encodedClaims.'.signature';
    }

    private function configureSharePointDiscovery(): void
    {
        Cache::flush();
        config(['services.microsoft_graph' => [
            'writes_enabled' => false,
            'calendar_writes_enabled' => false,
            'sharepoint_enabled' => true,
            'tenant_id' => 'tenant-test',
            'work_schedules' => [
                'site_id' => 'site-test',
            ],
        ]]);
        $this->fakeMicrosoftGraphBwsCredentials();
    }
}
