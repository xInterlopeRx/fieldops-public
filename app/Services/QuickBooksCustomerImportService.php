<?php

namespace App\Services;

use App\Exceptions\QuickBooksApiException;
use App\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class QuickBooksCustomerImportService
{
    public function __construct(
        private readonly QuickBooksApiClient $api,
        private readonly QuickBooksCustomerSyncService $sync,
    ) {}

    /** @return array{customers: int, sites: int, qbo_records: int} */
    public function preview(): array
    {
        $records = $this->api->allCustomers();
        [$parents, $children] = $this->partitionCustomerHierarchy($records);

        if ($parents === []) {
            throw new QuickBooksApiException('no_top_level_customers_found', 422);
        }

        return [
            'customers' => count($parents),
            'sites' => array_sum(array_map('count', $children)),
            'qbo_records' => count($records),
        ];
    }

    /** @return array{customers: int, sites: int, qbo_records: int} */
    public function replaceBusinessData(): array
    {
        $records = $this->api->allCustomers();
        [$parents, $children] = $this->partitionCustomerHierarchy($records);

        if ($parents === []) {
            throw new QuickBooksApiException('no_top_level_customers_found', 422);
        }

        $attachments = DB::table('work_order_attachments')->count()
            + DB::table('project_attachments')->count();

        if ($attachments > 0) {
            throw new \RuntimeException('Business data reset stopped because attachment metadata exists. Handle attachment records and stored files before retrying.');
        }

        return DB::transaction(function () use ($parents, $children, $records): array {
            $this->clearBusinessData();
            $sequence = 1001;
            $siteCount = 0;

            foreach ($parents as $parent) {
                $customerId = (string) $parent['Id'];
                $clientId = 'C'.str_pad((string) $sequence++, 5, '0', STR_PAD_LEFT);
                $customer = Customer::query()->create([
                    'client_id' => $clientId,
                    'quickbooks_id' => $customerId,
                    'name' => $parent['DisplayName'] ?? 'QuickBooks Customer '.$customerId,
                ]);
                $customerChildren = $children[$customerId] ?? [];
                $this->sync->importCustomerGraph($customer, $parent, $customerChildren);
                $siteCount += count($customerChildren);
            }

            return [
                'customers' => count($parents),
                'sites' => $siteCount,
                'qbo_records' => count($records),
            ];
        });
    }

    /**
     * Validate a complete single-level customer hierarchy before any local data is deleted.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<array<string, mixed>>>}
     */
    private function partitionCustomerHierarchy(array $records): array
    {
        $parentsById = [];
        $childrenByParent = [];
        $seenIds = [];

        foreach ($records as $record) {
            $id = $record['Id'] ?? null;

            if (! is_string($id) || $id === '' || isset($seenIds[$id])) {
                throw new QuickBooksApiException('invalid_or_duplicate_customer_id', 502);
            }

            $seenIds[$id] = true;
            $parentId = Arr::get($record, 'ParentRef.value');
            $isJob = ($record['Job'] ?? false) === true;

            if (! $isJob && $parentId === null) {
                $parentsById[$id] = $record;

                continue;
            }

            if (! $isJob || ! is_string($parentId) || $parentId === '') {
                throw new QuickBooksApiException('unsupported_customer_hierarchy', 422);
            }

            $childrenByParent[$parentId][] = $record;
        }

        foreach ($childrenByParent as $parentId => $children) {
            if (! isset($parentsById[$parentId])) {
                throw new QuickBooksApiException('nested_or_orphaned_customer_hierarchy', 422);
            }

            usort($children, fn (array $left, array $right): int => strcmp(
                (string) ($left['FullyQualifiedName'] ?? $left['DisplayName'] ?? $left['Id']),
                (string) ($right['FullyQualifiedName'] ?? $right['DisplayName'] ?? $right['Id']),
            ));
            $childrenByParent[$parentId] = $children;
        }

        uasort($parentsById, fn (array $left, array $right): int => strcmp(
            (string) ($left['FullyQualifiedName'] ?? $left['DisplayName'] ?? $left['Id']),
            (string) ($right['FullyQualifiedName'] ?? $right['DisplayName'] ?? $right['Id']),
        ));

        return [array_values($parentsById), $childrenByParent];
    }

    private function clearBusinessData(): void
    {
        foreach ([
            'visit_user',
            'work_order_user',
            'project_site',
            'work_order_attachments',
            'project_attachments',
            'visits',
            'work_orders',
            'projects',
            'contacts',
            'customer_addresses',
            'sites',
            'customers',
            'archive_batches',
        ] as $table) {
            DB::table($table)->delete();
        }
    }
}
