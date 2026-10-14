<?php

namespace App\Services;

use App\Exceptions\QuickBooksApiException;
use App\Jobs\PushQuickBooksCustomer;
use App\Jobs\PushQuickBooksSite;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuickBooksCustomerWriteService
{
    private const CUSTOMER_WRITE_FIELDS = [
        'name', 'company_name', 'first_name', 'middle_name', 'last_name',
        'title', 'suffix', 'email', 'phone', 'alternate_phone', 'mobile_phone',
        'fax', 'website', 'notes', 'preferred_delivery_method', 'quickbooks_active',
        'quickbooks_customer_type_id', 'BillAddr', 'ShipAddr',
    ];

    /** @var array<string, string> */
    private const CUSTOMER_FIELD_MAP = [
        'name' => 'DisplayName',
        'company_name' => 'CompanyName',
        'first_name' => 'GivenName',
        'middle_name' => 'MiddleName',
        'last_name' => 'FamilyName',
        'title' => 'Title',
        'suffix' => 'Suffix',
        'email' => 'PrimaryEmailAddr',
        'phone' => 'PrimaryPhone',
        'alternate_phone' => 'AlternatePhone',
        'mobile_phone' => 'Mobile',
        'fax' => 'Fax',
        'website' => 'WebAddr',
        'notes' => 'Notes',
        'preferred_delivery_method' => 'PreferredDeliveryMethod',
        'quickbooks_active' => 'Active',
        'quickbooks_customer_type_id' => 'CustomerTypeRef',
    ];

    public function __construct(
        private readonly QuickBooksApiClient $api,
        private readonly QuickBooksCustomerSyncService $sync,
        private readonly QuickBooksSyncControl $syncControl,
    ) {}

    public function sandboxWritesEnabled(): bool
    {
        return $this->api->isSandboxWriteEnabled();
    }

    /** @param list<string> $fields */
    public function queueCustomer(Customer $customer, array $fields = [], bool $createIfUnlinked = false): string
    {
        if ($customer->import_source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
            return 'csv_import_local_only';
        }

        if (! $this->api->isSandboxWriteEnabled()) {
            return 'not_connected';
        }

        if ($customer->quickbooks_id === null && ! $createIfUnlinked) {
            return 'unlinked';
        }

        if ($customer->quickbooks_write_request_id !== null) {
            return $this->syncControl->isEnabled() ? 'pending' : 'paused';
        }

        if ($customer->quickbooks_id === null) {
            $fields = self::CUSTOMER_WRITE_FIELDS;
        } else {
            $fields = array_values(array_intersect($fields, [...array_keys(self::CUSTOMER_FIELD_MAP), 'BillAddr', 'ShipAddr']));

            if ($fields === []) {
                return 'unchanged';
            }
        }

        if ($customer->quickbooks_sync_status === 'pending' && is_array($customer->quickbooks_write_fields)) {
            $fields = array_values(array_unique([...$customer->quickbooks_write_fields, ...$fields]));
        }

        $requestId = (string) Str::uuid();
        $customer->forceFill([
            'quickbooks_write_request_id' => $requestId,
            'quickbooks_write_fields' => $fields,
            'quickbooks_write_payload' => null,
            'quickbooks_sync_status' => 'pending',
            'quickbooks_sync_error' => null,
            'quickbooks_sync_requested_at' => now(),
        ])->save();

        try {
            PushQuickBooksCustomer::dispatch((int) $customer->getKey(), $requestId)
                ->onQueue($this->syncControl->queueForDispatch());
        } catch (\Throwable $exception) {
            $customer->forceFill([
                'quickbooks_sync_status' => 'failed',
                'quickbooks_sync_error' => 'queue_dispatch_failed',
            ])->save();
            report($exception);

            return 'failed';
        }

        return $this->syncControl->isEnabled() ? 'pending' : 'paused';
    }

    public function queueSite(Site $site): string
    {
        $site->loadMissing('customer');

        if ($site->import_source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
            || $site->customer?->import_source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
            return 'csv_import_local_only';
        }

        if (! $this->api->isSandboxWriteEnabled()) {
            return 'not_connected';
        }

        if (
            $site->customer?->quickbooks_id === null &&
            $site->customer?->quickbooks_write_request_id === null
        ) {
            return 'unlinked';
        }

        if ($site->quickbooks_write_request_id !== null) {
            return $this->syncControl->isEnabled() ? 'pending' : 'paused';
        }

        $requestId = (string) Str::uuid();
        $site->forceFill(['quickbooks_write_request_id' => $requestId])->save();
        $site->customer->forceFill([
            'quickbooks_sync_status' => 'pending',
            'quickbooks_sync_error' => null,
            'quickbooks_sync_requested_at' => now(),
        ])->save();

        try {
            PushQuickBooksSite::dispatch((int) $site->getKey(), $requestId)
                ->onQueue($this->syncControl->queueForDispatch());
        } catch (\Throwable $exception) {
            $site->customer->forceFill([
                'quickbooks_sync_status' => 'failed',
                'quickbooks_sync_error' => 'queue_dispatch_failed',
            ])->save();
            report($exception);

            return 'failed';
        }

        return $this->syncControl->isEnabled() ? 'pending' : 'paused';
    }

    public function pushCustomer(int $customerId, string $requestId): void
    {
        if (! $this->api->isSandboxWriteEnabled()) {
            throw new QuickBooksApiException('sandbox_writes_disabled', 403);
        }

        $customer = Customer::query()->with('addresses')->findOrFail($customerId);

        if ($customer->import_source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
            throw new QuickBooksApiException('csv_import_customer_is_local_only', 403);
        }

        if ($customer->quickbooks_write_request_id !== $requestId) {
            return;
        }

        $payload = $customer->quickbooks_write_payload;

        if (! is_array($payload)) {
            $isCreate = $customer->quickbooks_id === null;
            $payload = $this->customerPayload(
                $customer,
                $isCreate,
                $customer->quickbooks_write_fields ?? self::CUSTOMER_WRITE_FIELDS,
            );

            if (! $isCreate) {
                $currentQuickBooksCustomer = $this->api->customer((string) $customer->quickbooks_id);

                if (
                    ($currentQuickBooksCustomer['Id'] ?? null) !== $customer->quickbooks_id ||
                    ($currentQuickBooksCustomer['Job'] ?? false) === true ||
                    isset($currentQuickBooksCustomer['ParentRef']['value'])
                ) {
                    throw new QuickBooksApiException('invalid_customer_hierarchy', 409);
                }

                $syncToken = $currentQuickBooksCustomer['SyncToken'] ?? null;

                if (! is_string($syncToken) || $syncToken === '') {
                    throw new QuickBooksApiException('missing_sync_token', 409);
                }

                if (
                    ! is_string($customer->quickbooks_sync_token) ||
                    ! hash_equals($customer->quickbooks_sync_token, $syncToken)
                ) {
                    throw new QuickBooksApiException('quickbooks_conflict', 409);
                }

                $payload['Id'] = $customer->quickbooks_id;
                $payload['SyncToken'] = $syncToken;
                $payload['sparse'] = true;
            }

            $payload = DB::transaction(function () use ($customerId, $requestId, $payload): ?array {
                $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customerId);

                if ($lockedCustomer->quickbooks_write_request_id !== $requestId) {
                    return null;
                }

                if (is_array($lockedCustomer->quickbooks_write_payload)) {
                    return $lockedCustomer->quickbooks_write_payload;
                }

                $lockedCustomer->forceFill(['quickbooks_write_payload' => $payload])->save();

                return $payload;
            });

            if ($payload === null) {
                return;
            }
        }

        $response = $this->api->writeCustomer($payload, $requestId);
        $qboId = (string) $response['Id'];

        $nextRequestId = DB::transaction(function () use ($customerId, $requestId, $qboId, $response): ?string {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

            if ($customer->quickbooks_id !== null && $customer->quickbooks_id !== $qboId) {
                throw new QuickBooksApiException('quickbooks_id_mismatch', 409);
            }

            if ($customer->quickbooks_write_request_id !== $requestId) {
                $customer->forceFill([
                    'quickbooks_id' => $qboId,
                    'quickbooks_sync_token' => $response['SyncToken'] ?? $customer->quickbooks_sync_token,
                    'quickbooks_sync_status' => 'pending',
                ])->save();

                return $customer->quickbooks_write_request_id;
            }

            $customer->forceFill([
                'quickbooks_id' => $qboId,
                'quickbooks_sync_token' => $response['SyncToken'] ?? null,
            ])->save();
            $this->sync->importCustomerGraph($customer, $response, []);
            $customer->forceFill([
                'quickbooks_write_request_id' => null,
                'quickbooks_write_fields' => null,
                'quickbooks_write_payload' => null,
            ])->save();
            if (Site::query()->where('customer_id', $customer->id)->whereNotNull('quickbooks_write_request_id')->exists()) {
                $customer->forceFill(['quickbooks_sync_status' => 'pending'])->save();
            }

            return null;
        });

        if ($nextRequestId !== null) {
            PushQuickBooksCustomer::dispatch($customerId, $nextRequestId)
                ->onQueue($this->syncControl->queueForDispatch());
        }
    }

    public function pushSite(int $siteId, string $requestId): void
    {
        if (! $this->api->isSandboxWriteEnabled()) {
            throw new QuickBooksApiException('sandbox_writes_disabled', 403);
        }

        $site = Site::query()->with(['customer', 'contacts'])->findOrFail($siteId);

        if ($site->import_source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
            throw new QuickBooksApiException('csv_import_site_is_local_only', 403);
        }

        if ($site->quickbooks_write_request_id !== $requestId) {
            return;
        }

        $customer = $site->customer;

        if ($customer === null) {
            throw new QuickBooksApiException('parent_customer_not_linked', 409);
        }

        if ($customer->import_source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
            throw new QuickBooksApiException('csv_import_site_is_local_only', 403);
        }

        if (! is_string($customer->quickbooks_id) || $customer->quickbooks_id === '') {
            throw new QuickBooksApiException(
                $customer->quickbooks_write_request_id !== null ? 'parent_customer_write_pending' : 'parent_customer_not_linked',
                $customer->quickbooks_write_request_id !== null ? 503 : 409,
            );
        }

        $payload = [
            'DisplayName' => $site->name,
            'Job' => true,
            'ParentRef' => ['value' => $customer->quickbooks_id],
            'Active' => $site->quickbooks_active ?? true,
            'BillWithParent' => $site->quickbooks_bill_with_parent ?? false,
        ];

        if (is_string($site->address) && trim($site->address) !== '') {
            $payload['ShipAddr'] = ['Line1' => $site->address];
        }

        $primaryContact = $site->contacts->firstWhere('contact_type', 'primary');

        if ($primaryContact instanceof Contact) {
            $payload = [...$payload, ...$this->siteContactPayload($primaryContact)];
        }

        if ($site->quickbooks_id !== null) {
            $currentQuickBooksSite = $this->api->customer($site->quickbooks_id);

            if (
                ($currentQuickBooksSite['Id'] ?? null) !== $site->quickbooks_id ||
                ($currentQuickBooksSite['Job'] ?? false) !== true ||
                (string) ($currentQuickBooksSite['ParentRef']['value'] ?? '') !== $customer->quickbooks_id
            ) {
                throw new QuickBooksApiException('invalid_site_hierarchy', 409);
            }

            $syncToken = $currentQuickBooksSite['SyncToken'] ?? null;

            if (! is_string($syncToken) || $syncToken === '') {
                throw new QuickBooksApiException('missing_sync_token', 409);
            }

            if (
                ! is_string($site->quickbooks_sync_token) ||
                ! hash_equals($site->quickbooks_sync_token, $syncToken)
            ) {
                throw new QuickBooksApiException('quickbooks_conflict', 409);
            }

            $payload['Id'] = $site->quickbooks_id;
            $payload['SyncToken'] = $syncToken;
            $payload['sparse'] = true;
        }

        $response = $this->api->writeCustomer($payload, $requestId);
        $qboId = (string) $response['Id'];

        $nextRequestId = DB::transaction(function () use ($siteId, $requestId, $qboId, $response, $customer): ?string {
            $site = Site::query()->lockForUpdate()->findOrFail($siteId);

            if ($site->quickbooks_write_request_id !== $requestId) {
                $site->forceFill([
                    'quickbooks_id' => $qboId,
                    'quickbooks_sync_token' => $response['SyncToken'] ?? $site->quickbooks_sync_token,
                ])->save();

                return $site->quickbooks_write_request_id;
            }

            $site->forceFill([
                'quickbooks_id' => $qboId,
                'quickbooks_sync_token' => $response['SyncToken'] ?? null,
                'quickbooks_write_request_id' => null,
            ])->save();
            $this->sync->applyWrittenSite($site, $customer, $response);
            $customer->forceFill([
                'quickbooks_sync_status' => Site::query()
                    ->where('customer_id', $customer->id)
                    ->whereNotNull('quickbooks_write_request_id')
                    ->exists() ? 'pending' : 'synced',
                'quickbooks_sync_error' => null,
                'quickbooks_last_synced_at' => now(),
            ])->save();

            return null;
        });

        if ($nextRequestId !== null) {
            PushQuickBooksSite::dispatch($siteId, $nextRequestId)
                ->onQueue($this->syncControl->queueForDispatch());
        }
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function customerPayload(Customer $customer, bool $isCreate, array $fields): array
    {
        $payload = [];

        foreach ($fields as $field) {
            if ($field === 'BillAddr' || $field === 'ShipAddr') {
                $addressType = $field === 'BillAddr' ? CustomerAddress::TYPE_BILLING : CustomerAddress::TYPE_SHIPPING;
                $address = $customer->addresses
                    ->first(fn (CustomerAddress $candidate): bool => $candidate->address_type === $addressType && $candidate->is_default);

                if ($address !== null) {
                    $payload[$field] = $this->physicalAddress($address);
                } elseif ($field === 'BillAddr' && $isCreate && is_string($customer->address) && trim($customer->address) !== '') {
                    $payload[$field] = ['Line1' => $customer->address];
                }

                continue;
            }

            $apiField = self::CUSTOMER_FIELD_MAP[$field] ?? null;

            if ($apiField === null) {
                continue;
            }

            $value = match ($field) {
                'email' => $customer->email === null ? null : ['Address' => $customer->email],
                'phone' => $customer->phone === null ? null : ['FreeFormNumber' => $customer->phone],
                'alternate_phone' => $customer->alternate_phone === null ? null : ['FreeFormNumber' => $customer->alternate_phone],
                'mobile_phone' => $customer->mobile_phone === null ? null : ['FreeFormNumber' => $customer->mobile_phone],
                'fax' => $customer->fax === null ? null : ['FreeFormNumber' => $customer->fax],
                'website' => $customer->website === null ? null : ['URI' => $customer->website],
                'notes' => $customer->notes ?? '',
                'quickbooks_active' => $customer->quickbooks_active ?? true,
                'quickbooks_customer_type_id' => $customer->quickbooks_customer_type_id === null
                    ? null
                    : ['value' => $customer->quickbooks_customer_type_id],
                default => $customer->getAttribute($field),
            };

            if ($value !== null || ! $isCreate) {
                $payload[$apiField] = $value;
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function physicalAddress(CustomerAddress $address): array
    {
        $payload = [
            'Line1' => $address->line1,
            'Line2' => $address->line2,
            'Line3' => $address->line3,
            'Line4' => $address->line4,
            'Line5' => $address->line5,
            'City' => $address->city,
            'CountrySubDivisionCode' => $address->country_subdivision_code,
            'PostalCode' => $address->postal_code,
            'Country' => $address->country,
        ];

        if ($address->quickbooks_address_id !== null) {
            $payload['Id'] = $address->quickbooks_address_id;
        }

        return array_filter($payload, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, mixed> */
    private function siteContactPayload(Contact $contact): array
    {
        return [
            'GivenName' => $contact->first_name,
            'MiddleName' => $contact->middle_name,
            'FamilyName' => $contact->last_name,
            'Title' => $contact->title,
            'Suffix' => $contact->suffix,
            'PrimaryEmailAddr' => $contact->email === null ? null : ['Address' => $contact->email],
            'PrimaryPhone' => $contact->phone === null ? null : ['FreeFormNumber' => $contact->phone],
            'AlternatePhone' => $contact->alternate_phone === null ? null : ['FreeFormNumber' => $contact->alternate_phone],
            'Mobile' => $contact->mobile_phone === null ? null : ['FreeFormNumber' => $contact->mobile_phone],
            'Fax' => $contact->fax === null ? null : ['FreeFormNumber' => $contact->fax],
            'WebAddr' => $contact->website === null ? null : ['URI' => $contact->website],
        ];
    }
}
