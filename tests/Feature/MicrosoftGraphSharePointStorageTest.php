<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\ApplicationSetupState;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MicrosoftGraphSharePointStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
    }

    public function test_upload_creates_missing_parent_folders_and_returns_file_metadata(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        $contents = 'pdf-bytes';
        $graphRequests = [];
        $unexpectedRequests = [];
        $uploadedContent = null;
        $uploadedContentType = null;

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/*' => function (HttpRequest $request) use (&$graphRequests, &$unexpectedRequests, &$uploadedContent, &$uploadedContentType, $contents) {
                $method = $request->method();
                $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
                $graphRequests[] = [$method, $path];

                if ($method === 'GET' && $path === '/v1.0/sites/site-test/drives') {
                    return Http::response(['value' => [[
                        'id' => 'drive-documents',
                        'name' => 'Documents',
                    ]]]);
                }

                if ($method === 'GET' && str_starts_with($path, '/v1.0/drives/drive-documents/root:/')) {
                    return Http::response(['error' => ['code' => 'itemNotFound']], 404);
                }

                if ($method === 'POST' && $path === '/v1.0/drives/drive-documents/root/children') {
                    return Http::response([
                        'id' => 'fieldops-folder',
                        'name' => 'FieldOps',
                        'folder' => ['childCount' => 0],
                    ], 201);
                }

                if ($method === 'POST' && $path === '/v1.0/drives/drive-documents/items/fieldops-folder/children') {
                    return Http::response([
                        'id' => 'client-folder',
                        'name' => 'customer-7',
                        'folder' => ['childCount' => 0],
                    ], 201);
                }

                if ($method === 'PUT' && $path === '/v1.0/drives/drive-documents/root:/FieldOps/customer-7/report.pdf:/content') {
                    $uploadedContent = $request->body();
                    $uploadedContentType = $request->header('Content-Type');

                    return Http::response([
                        'id' => 'item-report-7',
                        'name' => 'report.pdf',
                        'size' => strlen($contents),
                        'eTag' => 'etag-report-7',
                        'webUrl' => 'https://example.sharepoint.com/sites/work-schedules/report.pdf',
                        'file' => ['mimeType' => 'application/pdf'],
                    ], 201);
                }

                $unexpectedRequests[] = [$method, $path];

                return Http::response(['error' => ['code' => 'unexpectedRequest']], 500);
            },
        ]);

        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('SharePoint storage file uploaded', $message);
            $this->assertSame('site-test', $context['site_id']);
            $this->assertSame('drive-documents', $context['drive_id']);
            $this->assertSame('item-report-7', $context['item_id']);
            $this->assertSame(strlen('pdf-bytes'), $context['size']);

            return true;
        });

        $metadata = app(MicrosoftGraphSharePointStorageService::class)
            ->upload('FieldOps/customer-7/report.pdf', $contents, 'application/pdf');

        $this->assertSame($contents, $uploadedContent);
        $this->assertSame(['application/pdf'], $uploadedContentType);
        $this->assertSame([
            'id' => 'item-report-7',
            'name' => 'report.pdf',
            'size' => 9,
            'mime_type' => 'application/pdf',
            'e_tag' => 'etag-report-7',
            'c_tag' => null,
            'last_modified_at' => null,
            'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/report.pdf',
        ], $metadata);
        $this->assertSame([], $unexpectedRequests);
        $this->assertSame([
            ['GET', '/v1.0/sites/site-test/drives'],
            ['GET', '/v1.0/drives/drive-documents/root:/FieldOps'],
            ['POST', '/v1.0/drives/drive-documents/root/children'],
            ['GET', '/v1.0/drives/drive-documents/root:/FieldOps/customer-7'],
            ['POST', '/v1.0/drives/drive-documents/items/fieldops-folder/children'],
            ['GET', '/v1.0/drives/drive-documents/root:/FieldOps/customer-7/report.pdf'],
            ['PUT', '/v1.0/drives/drive-documents/root:/FieldOps/customer-7/report.pdf:/content'],
        ], $graphRequests);
    }

    public function test_metadata_returns_only_file_metadata_for_the_configured_drive(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/items/item-42*' => Http::response([
                'id' => 'item-42',
                'name' => 'invoice.pdf',
                'size' => 1536,
                'eTag' => 'etag-42',
                'cTag' => 'ctag-42',
                'lastModifiedDateTime' => '2026-10-05T12:00:00Z',
                'webUrl' => 'https://example.sharepoint.com/sites/work-schedules/invoice.pdf',
                'file' => ['mimeType' => 'application/pdf'],
            ]),
        ]);

        $metadata = app(MicrosoftGraphSharePointStorageService::class)->metadata('item-42');

        $this->assertSame([
            'id' => 'item-42',
            'name' => 'invoice.pdf',
            'size' => 1536,
            'mime_type' => 'application/pdf',
            'e_tag' => 'etag-42',
            'c_tag' => 'ctag-42',
            'last_modified_at' => '2026-10-05T12:00:00Z',
            'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/invoice.pdf',
        ], $metadata);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/drives/drive-documents/items/item-42?'));
    }

    public function test_upload_replaces_a_file_at_the_same_relative_path(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/root:/report.pdf:/content' => Http::response([
                'id' => 'item-report',
                'name' => 'report.pdf',
                'size' => 7,
                'eTag' => 'etag-updated',
                'file' => ['mimeType' => 'application/pdf'],
            ], 200),
            'graph.microsoft.com/v1.0/drives/drive-documents/root:/report.pdf*' => Http::response([
                'id' => 'item-report',
                'name' => 'report.pdf',
                'size' => 7,
                'eTag' => 'etag-current',
                'file' => ['mimeType' => 'application/pdf'],
            ]),
        ]);

        app(MicrosoftGraphSharePointStorageService::class)->upload('report.pdf', 'new-pdf', 'application/pdf');

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/root:/report.pdf:/content')
            && $request->body() === 'new-pdf');
    }

    public function test_download_follows_graph_preauthenticated_redirect_without_forwarding_the_bearer_token(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        $contents = "sharepoint file bytes\0";
        $downloadUrl = 'https://example.files.1drv.com/content?tempauth=example-download-token';

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/items/item-42*' => function (HttpRequest $request) use ($contents, $downloadUrl) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if (str_ends_with($path, '/content')) {
                    return Http::response('', 302, ['Location' => $downloadUrl]);
                }

                return Http::response([
                    'id' => 'item-42',
                    'name' => 'invoice.pdf',
                    'size' => strlen($contents),
                    'file' => ['mimeType' => 'application/pdf'],
                ]);
            },
            'example.files.1drv.com/content*' => Http::response($contents, 200, [
                'Content-Length' => (string) strlen($contents),
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $downloaded = app(MicrosoftGraphSharePointStorageService::class)->download('item-42');

        $this->assertSame($contents, $downloaded);
        Http::assertSent(fn (HttpRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'example.files.1drv.com'
            && $request->header('Authorization') === []);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/items/item-42/content')
            && $request->header('Authorization') === ['Bearer mock-sharepoint-token']);
    }

    public function test_thumbnail_fetches_a_graph_generated_image_without_the_bearer_token(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        $thumbnailUrl = 'https://example.onedrive.com/thumbnail?tempauth=example-download-token';
        $thumbnailBytes = 'bounded-graph-thumbnail';
        $graphRequests = [];

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/*' => function (HttpRequest $request) use (&$graphRequests, $thumbnailUrl) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                $graphRequests[] = $path;

                if ($path === '/v1.0/sites/site-test/drives') {
                    return Http::response(['value' => [[
                        'id' => 'drive-documents',
                        'name' => 'Documents',
                    ]]]);
                }

                if (str_ends_with($path, '/thumbnails')) {
                    return Http::response(['value' => [[
                        'id' => '0',
                        'medium' => [
                            'url' => $thumbnailUrl,
                            'width' => 176,
                            'height' => 132,
                        ],
                    ]]]);
                }

                return Http::response([
                    'id' => 'item-image',
                    'name' => 'equipment.png',
                    'size' => 2048,
                    'file' => ['mimeType' => 'image/png'],
                ]);
            },
            'example.onedrive.com/*' => Http::response($thumbnailBytes, 200, [
                'Content-Length' => (string) strlen($thumbnailBytes),
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $thumbnail = app(MicrosoftGraphSharePointStorageService::class)->thumbnail('item-image');

        $this->assertSame([
            'contents' => $thumbnailBytes,
            'width' => 176,
            'height' => 132,
        ], $thumbnail);
        $this->assertContains('/v1.0/drives/drive-documents/items/item-image/thumbnails', $graphRequests);
        Http::assertSent(fn (HttpRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'example.onedrive.com'
            && $request->header('Authorization') === []);
    }

    public function test_thumbnail_rejects_an_untrusted_graph_thumbnail_url_without_requesting_it(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/*' => function (HttpRequest $request) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($path === '/v1.0/sites/site-test/drives') {
                    return Http::response(['value' => [[
                        'id' => 'drive-documents',
                        'name' => 'Documents',
                    ]]]);
                }

                if (str_ends_with($path, '/thumbnails')) {
                    return Http::response(['value' => [[
                        'small' => [
                            'url' => 'https://attacker.example/thumbnail?token=private',
                            'width' => 96,
                            'height' => 96,
                        ],
                    ]]]);
                }

                return Http::response([
                    'id' => 'item-image',
                    'name' => 'equipment.png',
                    'size' => 2048,
                    'file' => ['mimeType' => 'image/png'],
                ]);
            },
        ]);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->thumbnail('item-image');
            $this->fail('Expected an untrusted thumbnail URL to be rejected.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_download_redirect_invalid', $exception->reason);
        }

        Http::assertNotSent(fn (HttpRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'attacker.example');
    }

    public function test_download_rejects_untrusted_redirect_hosts_without_requesting_them(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/items/item-42*' => function (HttpRequest $request) {
                if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/content')) {
                    return Http::response('', 302, ['Location' => 'https://attacker.example/file?token=secret']);
                }

                return Http::response([
                    'id' => 'item-42',
                    'name' => 'invoice.pdf',
                    'size' => 4,
                    'file' => ['mimeType' => 'application/pdf'],
                ]);
            },
        ]);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->download('item-42');
            $this->fail('Expected an untrusted preauthenticated download URL to be rejected.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_download_redirect_invalid', $exception->reason);
        }

        Http::assertNotSent(fn (HttpRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'attacker.example');
    }

    public function test_download_rejects_remote_files_above_the_provider_limit_before_fetching_content(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/items/item-large*' => Http::response([
                'id' => 'item-large',
                'name' => 'large.bin',
                'size' => (20 * 1024 * 1024) + 1,
                'file' => ['mimeType' => 'application/octet-stream'],
            ]),
        ]);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->download('item-large');
            $this->fail('Expected oversized remote content to be rejected.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_sharepoint_storage_file_too_large', $exception->reason);
        }

        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/items/item-large/content',
        ));
    }

    public function test_delete_removes_only_a_file_in_the_configured_drive(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/items/item-42*' => function (HttpRequest $request) {
                if ($request->method() === 'DELETE') {
                    return Http::response([], 204);
                }

                return Http::response([
                    'id' => 'item-42',
                    'name' => 'invoice.pdf',
                    'size' => 12,
                    'eTag' => 'etag-42',
                    'file' => ['mimeType' => 'application/pdf'],
                ]);
            },
        ]);

        app(MicrosoftGraphSharePointStorageService::class)->delete('item-42');

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/drives/drive-documents/items/item-42')
            && $request->header('If-Match') === ['etag-42']);
    }

    public function test_delete_refuses_a_remote_file_without_an_e_tag(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/items/item-42*' => Http::response([
                'id' => 'item-42',
                'name' => 'invoice.pdf',
                'size' => 12,
                'file' => ['mimeType' => 'application/pdf'],
            ]),
        ]);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->delete('item-42');
            $this->fail('Expected a file without an eTag to be left unchanged.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_sharepoint_storage_remote_version_unavailable', $exception->reason);
        }

        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE');
    }

    public function test_delete_is_idempotent_when_the_item_is_already_absent(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'drive-documents',
                'name' => 'Documents',
            ]]]),
            'graph.microsoft.com/v1.0/drives/drive-documents/items/item-missing*' => Http::response([
                'error' => ['code' => 'itemNotFound'],
            ], 404),
        ]);

        app(MicrosoftGraphSharePointStorageService::class)->delete('item-missing');

        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE');
    }

    public function test_storage_is_disabled_by_default_and_rejects_invalid_paths_before_network_access(): void
    {
        Http::preventStrayRequests();
        config(['services.microsoft_graph.sharepoint_enabled' => false]);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->metadata('item-42');
            $this->fail('Expected disabled SharePoint storage to reject metadata retrieval.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_sharepoint_storage_disabled', $exception->reason);
        }

        config(['services.microsoft_graph.sharepoint_enabled' => true]);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->upload('../private.pdf', 'bytes', 'application/pdf');
            $this->fail('Expected path traversal to be rejected.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_sharepoint_storage_path_invalid', $exception->reason);
        }

        try {
            app(MicrosoftGraphSharePointStorageService::class)->upload('private.pdf', 'bytes', "application/pdf\r\nAuthorization: secret");
            $this->fail('Expected an invalid content type to be rejected.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_sharepoint_storage_content_type_invalid', $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_upload_refuses_a_configured_drive_not_listed_under_the_configured_site(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/site-test/drives*' => Http::response(['value' => [[
                'id' => 'other-drive',
                'name' => 'Other library',
            ]]]),
        ]);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->upload('report.pdf', 'pdf-bytes', 'application/pdf');
            $this->fail('Expected the storage provider to reject an unlisted drive.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_sharepoint_configured_drive_not_in_site', $exception->reason);
        }

        Http::assertNotSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://graph.microsoft.com/')
            && $request->method() !== 'GET');
    }

    public function test_upload_rejects_objects_larger_than_the_provider_limit_before_network_access(): void
    {
        $this->configureSharePointStorage();
        Http::preventStrayRequests();
        $contents = str_repeat('x', (20 * 1024 * 1024) + 1);

        try {
            app(MicrosoftGraphSharePointStorageService::class)->upload('large.bin', $contents);
            $this->fail('Expected oversized content to be rejected.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_sharepoint_storage_file_too_large', $exception->reason);
        }

        Http::assertNothingSent();
    }

    private function configureSharePointStorage(): void
    {
        Cache::flush();
        config(['services.microsoft_graph' => [
            'writes_enabled' => false,
            'calendar_writes_enabled' => false,
            'sharepoint_enabled' => true,
            'tenant_id' => 'tenant-test',
            'work_schedules' => [
                'site_id' => 'site-test',
                'drive_id' => 'drive-documents',
            ],
        ]]);
        $this->fakeMicrosoftGraphBwsCredentials();
    }
}
