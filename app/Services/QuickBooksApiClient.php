<?php

namespace App\Services;

use App\Exceptions\QuickBooksApiException;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class QuickBooksApiClient
{
    private const MAX_RESULTS = 1000;

    private const IMPORT_PAGE_SIZE = 50;

    private const MAX_ITEM_PAGE_SIZE = 50;

    public function __construct(
        private readonly QuickBooksOAuthService $oauth,
        private readonly QuickBooksSyncControl $syncControl,
    ) {}

    public function isConnectedForCurrentEnvironment(): bool
    {
        if (! $this->oauth->apiModeEnabled()) {
            return false;
        }

        $connection = IntegrationConnection::query()
            ->where('provider', 'quickbooks')
            ->first();

        return $connection !== null
            && $connection->environment === $this->oauth->environment()
            && $this->hasUsableCredentials($connection);
    }

    /** @return array{customers: int, qbo_records: int} */
    public function customerImportCounts(): array
    {
        return [
            'customers' => $this->topLevelCustomerCount(),
            'qbo_records' => $this->countCustomerRecords(
                'select * from Customer where Active IN (true, false) ORDERBY FullyQualifiedName',
            ),
        ];
    }

    /** @return array{records: list<array{id: string, name: string, description: ?string, type: string, active: bool}>, total_count: ?int} */
    public function itemPage(int $startPosition, int $maxResults = self::MAX_ITEM_PAGE_SIZE): array
    {
        if ($startPosition < 1 || $maxResults < 1 || $maxResults > self::MAX_ITEM_PAGE_SIZE) {
            throw new QuickBooksApiException('invalid_item_page', 422);
        }

        $response = $this->get('query', [
            'query' => 'select * from Item where Active IN (true, false) ORDERBY Name startposition '.$startPosition.' maxresults '.$maxResults,
        ]);
        $queryResponse = $response['QueryResponse'] ?? null;

        if (! is_array($queryResponse)) {
            throw new QuickBooksApiException('invalid_items_response', 502);
        }

        $rawItems = $queryResponse['Item'] ?? [];

        if (is_array($rawItems) && isset($rawItems['Id'])) {
            $rawItems = [$rawItems];
        }

        if (! is_array($rawItems)) {
            throw new QuickBooksApiException('invalid_items_response', 502);
        }

        $items = [];

        foreach ($rawItems as $rawItem) {
            $item = $this->safeItem($rawItem);

            if ($item === null) {
                throw new QuickBooksApiException('invalid_items_response', 502);
            }

            $items[] = $item;
        }

        $totalValue = $queryResponse['totalCount'] ?? null;
        $total = $totalValue === null ? null : filter_var($totalValue, FILTER_VALIDATE_INT);

        if ($totalValue !== null && ($total === false || (int) $total < 0)) {
            throw new QuickBooksApiException('invalid_items_response', 502);
        }

        return [
            'records' => array_values($items),
            'total_count' => is_int($total) ? $total : null,
        ];
    }

    public function itemImportCount(): int
    {
        $response = $this->get('query', [
            'query' => 'select count(*) from Item where Active IN (true, false)',
        ]);
        $queryResponse = $response['QueryResponse'] ?? null;

        if (! is_array($queryResponse)) {
            throw new QuickBooksApiException('invalid_item_count_response', 502);
        }

        $items = $queryResponse['Item'] ?? null;
        $countValue = $queryResponse['totalCount']
            ?? $queryResponse['Count']
            ?? (is_array($items) ? ($items['Count'] ?? (array_is_list($items) ? ($items[0]['Count'] ?? null) : null)) : null);
        $count = filter_var($countValue, FILTER_VALIDATE_INT);

        if ($count === false || $count < 0) {
            throw new QuickBooksApiException('invalid_item_count_response', 502);
        }

        return $count;
    }

    public function topLevelCustomerCount(): int
    {
        return $this->countCustomerRecords(
            'select * from Customer where Job = false and Active IN (true, false) ORDERBY FullyQualifiedName',
        );
    }

    /** @return array{records: list<array<string, mixed>>, total_count: ?int} */
    public function topLevelCustomerPage(int $startPosition, int $maxResults = self::IMPORT_PAGE_SIZE): array
    {
        return $this->customerQueryPage(
            'select * from Customer where Job = false and Active IN (true, false) ORDERBY FullyQualifiedName',
            $startPosition,
            $maxResults,
            'invalid_customers_response',
        );
    }

    /** @return array{records: list<array<string, mixed>>, total_count: ?int} */
    private function customerQueryPage(string $query, int $startPosition, int $maxResults, string $failureCode): array
    {
        if ($startPosition < 1 || $maxResults < 1 || $maxResults > self::IMPORT_PAGE_SIZE) {
            throw new QuickBooksApiException('invalid_customer_page', 422);
        }

        $response = $this->get('query', [
            'query' => $query.' startposition '.$startPosition.' maxresults '.$maxResults,
        ]);
        $queryResponse = $response['QueryResponse'] ?? null;

        if (! is_array($queryResponse)) {
            throw new QuickBooksApiException($failureCode, 502);
        }

        $records = $queryResponse['Customer'] ?? [];

        if (is_array($records) && isset($records['Id'])) {
            $records = [$records];
        }

        if (! is_array($records)) {
            throw new QuickBooksApiException($failureCode, 502);
        }

        foreach ($records as $record) {
            if (! is_array($record)
                || ! is_string($record['Id'] ?? null)
                || $record['Id'] === ''
                || strlen($record['Id']) > 64) {
                throw new QuickBooksApiException($failureCode, 502);
            }
        }

        $totalCountValue = $queryResponse['totalCount'] ?? null;
        $totalCount = $totalCountValue === null
            ? null
            : filter_var($totalCountValue, FILTER_VALIDATE_INT);

        if ($totalCountValue !== null
            && ($totalCount === false || (is_int($totalCount) && $totalCount < 0))) {
            throw new QuickBooksApiException($failureCode, 502);
        }

        return ['records' => array_values($records), 'total_count' => is_int($totalCount) ? $totalCount : null];
    }

    private function countCustomerRecords(string $query): int
    {
        $startPosition = 1;
        $count = 0;

        do {
            $page = $this->customerQueryPage($query, $startPosition, self::IMPORT_PAGE_SIZE, 'invalid_customers_response');

            if ($page['total_count'] !== null) {
                return $page['total_count'];
            }

            $pageCount = count($page['records']);
            $count += $pageCount;
            $startPosition += $pageCount;
        } while ($pageCount === self::IMPORT_PAGE_SIZE);

        return $count;
    }

    /** @return array<string, mixed> */
    public function customer(string $customerId): array
    {
        $customerId = $this->validatedId($customerId);
        $response = $this->get("customer/{$customerId}");
        $customer = $response['Customer'] ?? null;

        if (! is_array($customer) || ! is_string($customer['Id'] ?? null)) {
            throw new QuickBooksApiException('invalid_customer_response', 502);
        }

        return $customer;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function writeCustomer(array $payload, string $requestId): array
    {
        $this->assertSyncEnabled();

        if ($requestId === '' || strlen($requestId) > 50) {
            throw new QuickBooksApiException('invalid_request_id', 422);
        }

        $connection = $this->connection();
        $token = $this->accessToken($connection);
        $response = $this->sendPost($connection, $token, 'customer', ['requestid' => $requestId], $payload);

        if ($response->status() === 401) {
            $this->assertSyncEnabled();
            $token = $this->accessToken($connection, rejectedToken: $token);
            $response = $this->sendPost($connection, $token, 'customer', ['requestid' => $requestId], $payload);
        }

        if (! $response->successful()) {
            throw $this->responseException($response->status(), $response->header('Retry-After'));
        }

        $entity = $response->json('Customer');

        if (! is_array($entity) || ! is_string($entity['Id'] ?? null)) {
            throw new QuickBooksApiException('invalid_customer_write_response', 502);
        }

        return $entity;
    }

    public function isSandboxWriteEnabled(): bool
    {
        if (! $this->oauth->apiModeEnabled()) {
            return false;
        }

        $connection = IntegrationConnection::query()
            ->where('provider', 'quickbooks')
            ->first();

        return $this->oauth->environment() === 'sandbox'
            && $connection !== null
            && $connection->environment === 'sandbox'
            && $this->hasUsableCredentials($connection);
    }

    /** @return list<array<string, mixed>> */
    public function allCustomers(): array
    {
        $customers = [];
        $startPosition = 1;

        do {
            $query = 'select * from Customer where Active IN (true, false) ORDERBY FullyQualifiedName startposition '.$startPosition.' maxresults '.self::MAX_RESULTS;
            $response = $this->get('query', ['query' => $query]);
            $queryResponse = $response['QueryResponse'] ?? null;

            if (! is_array($queryResponse)) {
                throw new QuickBooksApiException('invalid_customers_response', 502);
            }

            $records = $queryResponse['Customer'] ?? [];

            if (is_array($records) && isset($records['Id'])) {
                $records = [$records];
            }

            if (! is_array($records)) {
                throw new QuickBooksApiException('invalid_customers_response', 502);
            }

            foreach ($records as $record) {
                if (is_array($record) && is_string($record['Id'] ?? null)) {
                    $customers[] = $record;
                }
            }

            $count = count($records);
            $startPosition += $count;
            $totalCount = (int) ($queryResponse['totalCount'] ?? 0);
        } while ($count === self::MAX_RESULTS && ($totalCount === 0 || $startPosition <= $totalCount));

        return $customers;
    }

    /** @return list<array<string, mixed>> */
    public function childCustomers(
        string $parentCustomerId,
        string $parentFullyQualifiedName,
        ?int $maxRecords = null,
    ): array {
        $parentCustomerId = $this->validatedId($parentCustomerId);

        if ($maxRecords !== null && $maxRecords < 1) {
            throw new QuickBooksApiException('invalid_customer_page', 422);
        }

        $pageSize = $maxRecords === null ? self::MAX_RESULTS : self::IMPORT_PAGE_SIZE;
        $parentFullyQualifiedName = str_replace(
            ['\\', "'", '%', '_'],
            ['\\\\', "\\'", '\\%', '\\_'],
            $parentFullyQualifiedName,
        );
        $children = [];
        $startPosition = 1;

        do {
            $query = "select * from Customer where Job = true and FullyQualifiedName LIKE '{$parentFullyQualifiedName}:%' and Active IN (true, false) ORDERBY FullyQualifiedName startposition {$startPosition} maxresults {$pageSize}";
            $response = $this->get('query', ['query' => $query]);
            $queryResponse = $response['QueryResponse'] ?? null;

            if (! is_array($queryResponse)) {
                throw new QuickBooksApiException('invalid_children_response', 502);
            }

            $records = $queryResponse['Customer'] ?? [];

            if (is_array($records) && isset($records['Id'])) {
                $records = [$records];
            }

            if (! is_array($records)) {
                throw new QuickBooksApiException('invalid_children_response', 502);
            }

            foreach ($records as $record) {
                if (is_array($record)) {
                    $children[] = $record;

                    if ($maxRecords !== null && count($children) > $maxRecords) {
                        throw new QuickBooksApiException('customer_site_limit_exceeded', 422);
                    }
                }
            }

            $count = count($records);
            $startPosition += $count;
            $totalCount = (int) ($queryResponse['totalCount'] ?? 0);
        } while ($count === $pageSize && ($totalCount === 0 || $startPosition <= $totalCount));

        return $children;
    }

    private function validatedId(string $id): string
    {
        if ($id === '' || preg_match('/\A[0-9]+\z/', $id) !== 1) {
            throw new QuickBooksApiException('invalid_quickbooks_id', 422);
        }

        return $id;
    }

    /** @return array{id: string, name: string, description: ?string, type: string, active: bool}|null */
    private function safeItem(mixed $item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $id = $item['Id'] ?? null;
        $name = $item['Name'] ?? null;
        $type = $item['Type'] ?? null;
        $active = $item['Active'] ?? true;
        $description = $item['Description'] ?? null;

        if ((! is_string($id) && ! is_int($id))
            || preg_match('/\A[0-9]{1,64}\z/D', (string) $id) !== 1
            || ! is_string($name)
            || trim($name) === ''
            || mb_strlen($name) > 100
            || ! is_string($type)
            || trim($type) === ''
            || strlen($type) > 30
            || ! is_bool($active)
            || ($description !== null && ! is_string($description))) {
            return null;
        }

        return [
            'id' => (string) $id,
            'name' => trim($name),
            'description' => is_string($description) ? mb_substr($description, 0, 4000) : null,
            'type' => $type,
            'active' => $active,
        ];
    }

    /** @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $this->assertSyncEnabled();

        $connection = $this->connection();
        $token = $this->accessToken($connection);
        $response = $this->sendGet($connection, $token, $path, $query);

        if ($response->status() === 401) {
            $this->assertSyncEnabled();
            $token = $this->accessToken($connection, rejectedToken: $token);
            $response = $this->sendGet($connection, $token, $path, $query);
        }

        if (! $response->successful()) {
            throw $this->responseException($response->status(), $response->header('Retry-After'));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new QuickBooksApiException('invalid_api_response', 502);
        }

        return $payload;
    }

    private function assertSyncEnabled(): void
    {
        if (! $this->syncControl->isEnabled()) {
            throw new QuickBooksApiException('quickbooks_sync_paused', 423);
        }
    }

    private function sendGet(IntegrationConnection $connection, string $token, string $path, array $query): Response
    {
        $url = $this->apiUrl($connection, $path);

        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get($url, $query);
    }

    /** @param array<string, mixed> $query
     * @param  array<string, mixed>  $payload
     */
    private function sendPost(IntegrationConnection $connection, string $token, string $path, array $query, array $payload): Response
    {
        $url = $this->apiUrl($connection, $path).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->post($url, $payload);
    }

    private function apiUrl(IntegrationConnection $connection, string $path): string
    {
        $baseUrl = $connection->environment === 'sandbox'
            ? 'https://sandbox-quickbooks.api.intuit.com/v3/company/'
            : 'https://quickbooks.api.intuit.com/v3/company/';

        return $baseUrl.rawurlencode((string) $connection->realm_id).'/'.$path;
    }

    private function connection(): IntegrationConnection
    {
        if (! $this->oauth->apiModeEnabled()) {
            throw new QuickBooksApiException('quickbooks_api_disabled_in_csv_mode', 409);
        }

        $connection = IntegrationConnection::query()
            ->where('provider', 'quickbooks')
            ->first();

        if (
            $connection === null ||
            $connection->environment !== $this->oauth->environment() ||
            ! is_string($connection->realm_id) || $connection->realm_id === ''
        ) {
            throw new QuickBooksApiException('connection_unavailable', 409);
        }

        return $connection;
    }

    private function accessToken(IntegrationConnection $connection, ?string $rejectedToken = null): string
    {
        try {
            return $this->resolveAccessToken($connection, $rejectedToken);
        } catch (DecryptException) {
            throw new QuickBooksApiException('reauthorization_required', 401);
        }
    }

    private function resolveAccessToken(IntegrationConnection $connection, ?string $rejectedToken = null): string
    {
        $connection->refresh();

        if (
            $connection->access_token_expires_at?->isAfter(now()->addMinutes(2)) &&
            is_string($connection->access_token) &&
            $connection->access_token !== '' &&
            ($rejectedToken === null || ! hash_equals($rejectedToken, $connection->access_token))
        ) {
            return $connection->access_token;
        }

        try {
            return Cache::lock('quickbooks-token-refresh-'.$connection->getKey(), 30)
                ->block(10, function () use ($connection, $rejectedToken): string {
                    $connection->refresh();

                    if (
                        $connection->access_token_expires_at?->isAfter(now()->addMinutes(2)) &&
                        is_string($connection->access_token) &&
                        $connection->access_token !== '' &&
                        ($rejectedToken === null || ! hash_equals($rejectedToken, $connection->access_token))
                    ) {
                        return $connection->access_token;
                    }

                    if (
                        ! is_string($connection->refresh_token) || $connection->refresh_token === '' ||
                        $connection->refresh_token_expires_at?->isPast()
                    ) {
                        throw new QuickBooksApiException('reauthorization_required', 401);
                    }

                    try {
                        $tokens = $this->oauth->refreshAccessToken($connection->refresh_token);
                    } catch (ConnectionException) {
                        throw new QuickBooksApiException('token_service_unavailable', 503);
                    } catch (RequestException $exception) {
                        $status = $exception->response->status();
                        throw $this->responseException($status, $exception->response->header('Retry-After'));
                    } catch (UnexpectedValueException) {
                        throw new QuickBooksApiException('invalid_token_response', 502);
                    }

                    $connection->forceFill([
                        'access_token' => $tokens['access_token'],
                        'refresh_token' => $tokens['refresh_token'],
                        'access_token_expires_at' => $tokens['access_token_expires_at'],
                        'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
                    ])->save();

                    return $tokens['access_token'];
                });
        } catch (LockTimeoutException) {
            throw new QuickBooksApiException('token_refresh_busy', 503);
        }
    }

    private function hasUsableCredentials(IntegrationConnection $connection): bool
    {
        if (! is_string($connection->realm_id) || $connection->realm_id === '') {
            return false;
        }

        try {
            return is_string($connection->access_token)
                && $connection->access_token !== ''
                && is_string($connection->refresh_token)
                && $connection->refresh_token !== '';
        } catch (DecryptException) {
            return false;
        }
    }

    private function responseException(int $status, ?string $retryAfter): QuickBooksApiException
    {
        $reason = match (true) {
            $status === 401 => 'reauthorization_required',
            $status === 404 => 'quickbooks_record_not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'quickbooks_unavailable',
            default => 'quickbooks_request_failed',
        };

        $retryAfterSeconds = $status === 429 && is_numeric($retryAfter)
            ? min(3600, max(60, (int) $retryAfter))
            : null;

        return new QuickBooksApiException($reason, $status, $retryAfterSeconds);
    }
}
