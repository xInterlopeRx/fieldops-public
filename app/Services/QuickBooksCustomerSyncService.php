<?php

namespace App\Services;

use App\Exceptions\QuickBooksApiException;
use App\Jobs\SyncQuickBooksCustomer;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Site;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class QuickBooksCustomerSyncService
{
    public function __construct(
        private readonly QuickBooksApiClient $api,
        private readonly QuickBooksSyncControl $syncControl,
    ) {}

    public function requestRefresh(Customer $customer, bool $force = false): string
    {
        if (! $customer->quickbooks_id) {
            return 'unlinked';
        }

        if (! $this->syncControl->isEnabled()) {
            return 'sync_paused';
        }

        if (! $this->api->isConnectedForCurrentEnvironment()) {
            return 'not_connected';
        }

        if ($customer->quickbooks_write_request_id !== null) {
            return $customer->quickbooks_sync_status === 'failed' ? 'failed' : 'pending';
        }

        $decision = DB::transaction(function () use ($customer, $force): string {
            $lockedCustomer = Customer::query()
                ->lockForUpdate()
                ->find($customer->getKey());

            if ($lockedCustomer === null || ! $lockedCustomer->quickbooks_id) {
                return 'unlinked';
            }

            if ($lockedCustomer->quickbooks_sync_status === 'pending') {
                return 'pending';
            }

            $freshnessMinutes = max(1, (int) config('services.quickbooks.customer_sync_freshness_minutes', 360));

            if (
                ! $force &&
                $lockedCustomer->quickbooks_last_synced_at?->isAfter(now()->subMinutes($freshnessMinutes))
            ) {
                return 'synced';
            }

            $failureCooldownMinutes = max(1, (int) config('services.quickbooks.customer_sync_failure_cooldown_minutes', 5));

            if (
                ! $force &&
                $lockedCustomer->quickbooks_sync_status === 'failed' &&
                $lockedCustomer->quickbooks_sync_requested_at?->isAfter(now()->subMinutes($failureCooldownMinutes))
            ) {
                return 'failed';
            }

            $lockedCustomer->forceFill([
                'quickbooks_sync_status' => 'pending',
                'quickbooks_sync_error' => null,
                'quickbooks_sync_requested_at' => now(),
            ])->save();

            return 'dispatch';
        });

        if ($decision === 'dispatch') {
            try {
                SyncQuickBooksCustomer::dispatch((int) $customer->getKey())
                    ->onQueue($this->syncControl->queueForDispatch());
            } catch (Throwable $exception) {
                Customer::query()
                    ->whereKey($customer->getKey())
                    ->update([
                        'quickbooks_sync_status' => 'failed',
                        'quickbooks_sync_error' => 'queue_dispatch_failed',
                    ]);
                report($exception);

                return 'failed';
            }

            return 'pending';
        }

        return $decision;
    }

    public function sync(int $customerId): void
    {
        $customer = Customer::withArchived()->findOrFail($customerId);

        if ($customer->is_archived) {
            return;
        }

        if ($customer->quickbooks_write_request_id !== null) {
            return;
        }

        $quickBooksId = (string) $customer->quickbooks_id;
        $parent = $this->api->customer($quickBooksId);

        if (
            ($parent['Id'] ?? null) !== $quickBooksId ||
            ($parent['Job'] ?? false) === true ||
            isset($parent['ParentRef']['value'])
        ) {
            throw new QuickBooksApiException('invalid_customer_hierarchy', 422);
        }

        $parentFullyQualifiedName = $parent['FullyQualifiedName'] ?? $parent['DisplayName'] ?? null;

        if (! is_string($parentFullyQualifiedName) || trim($parentFullyQualifiedName) === '') {
            throw new QuickBooksApiException('invalid_customer_response', 502);
        }

        $children = $this->api->childCustomers($quickBooksId, $parentFullyQualifiedName);
        $children = array_values(array_filter(
            $children,
            fn (array $child): bool => ($child['Job'] ?? false) === true
                && (string) Arr::get($child, 'ParentRef.value', '') === $quickBooksId,
        ));

        DB::transaction(function () use ($customerId, $quickBooksId, $parent, $children): void {
            $customer = Customer::withArchived()
                ->lockForUpdate()
                ->findOrFail($customerId);

            if ($customer->is_archived) {
                return;
            }

            if ((string) $customer->quickbooks_id !== $quickBooksId) {
                throw new QuickBooksApiException('customer_link_changed', 409);
            }

            if ($customer->quickbooks_write_request_id !== null) {
                return;
            }

            $this->syncCustomerProfile($customer, $parent);
            $this->syncAddress($customer, CustomerAddress::TYPE_BILLING, Arr::get($parent, 'BillAddr'), authoritative: true);
            $this->syncAddress($customer, CustomerAddress::TYPE_SHIPPING, Arr::get($parent, 'ShipAddr'), authoritative: true);

            $childIds = [];

            foreach ($children as $child) {
                $childId = is_string($child['Id'] ?? null) ? $child['Id'] : null;

                if ($childId === null) {
                    continue;
                }

                $childIds[] = $childId;
                $this->syncSite($customer, $child, $childId, $quickBooksId, authoritative: true);
            }

            $missingSites = Site::query()
                ->where('customer_id', $customer->getKey())
                ->where('quickbooks_parent_id', $quickBooksId)
                ->whereNotNull('quickbooks_id');

            if ($childIds === []) {
                $missingSites->update(['quickbooks_active' => false]);
            } else {
                $missingSites->whereNotIn('quickbooks_id', $childIds)
                    ->update(['quickbooks_active' => false]);
            }

            $customer->forceFill([
                'quickbooks_sync_status' => 'synced',
                'quickbooks_sync_error' => null,
                'quickbooks_last_synced_at' => now(),
            ])->save();
        });
    }

    /**
     * Import a previously fetched parent customer and its direct QBO job records.
     * The caller owns the transaction and has validated the hierarchy.
     *
     * @param  list<array<string, mixed>>  $children
     */
    public function importCustomerGraph(Customer $customer, array $parent, array $children): void
    {
        if ($customer->is_archived) {
            return;
        }

        $quickBooksId = (string) $customer->quickbooks_id;

        if (
            $quickBooksId === '' ||
            ($parent['Id'] ?? null) !== $quickBooksId ||
            ($parent['Job'] ?? false) === true ||
            isset($parent['ParentRef']['value'])
        ) {
            throw new QuickBooksApiException('invalid_customer_hierarchy', 422);
        }

        $this->syncCustomerProfile($customer, $parent);
        $this->syncAddress(
            $customer,
            CustomerAddress::TYPE_BILLING,
            Arr::get($parent, 'BillAddr'),
            authoritative: array_key_exists('BillAddr', $parent),
        );
        $this->syncAddress(
            $customer,
            CustomerAddress::TYPE_SHIPPING,
            Arr::get($parent, 'ShipAddr'),
            authoritative: array_key_exists('ShipAddr', $parent),
        );

        foreach ($children as $child) {
            $childId = $child['Id'] ?? null;

            if (
                ! is_string($childId) ||
                ($child['Job'] ?? false) !== true ||
                (string) Arr::get($child, 'ParentRef.value', '') !== $quickBooksId
            ) {
                throw new QuickBooksApiException('invalid_customer_hierarchy', 422);
            }

            $this->syncSite($customer, $child, $childId, $quickBooksId, authoritative: true);
        }

        $customer->forceFill([
            'quickbooks_sync_status' => 'synced',
            'quickbooks_sync_error' => null,
            'quickbooks_sync_requested_at' => now(),
            'quickbooks_last_synced_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $payload */
    public function applyWrittenSite(Site $site, Customer $customer, array $payload): void
    {
        $siteId = $payload['Id'] ?? null;
        $parentId = $customer->quickbooks_id;

        if (
            ! is_string($siteId) || $siteId === '' ||
            ! is_string($parentId) || $parentId === '' ||
            ($payload['Job'] ?? false) !== true ||
            (string) Arr::get($payload, 'ParentRef.value', '') !== $parentId
        ) {
            throw new QuickBooksApiException('invalid_customer_hierarchy', 422);
        }

        $this->syncSite($customer, $payload, $siteId, $parentId, $site, authoritative: true);
    }

    private function syncCustomerProfile(Customer $customer, array $payload): void
    {
        $customer->forceFill([
            'name' => $payload['DisplayName'] ?? $customer->name,
            'company_name' => $payload['CompanyName'] ?? null,
            'first_name' => $payload['GivenName'] ?? null,
            'middle_name' => $payload['MiddleName'] ?? null,
            'last_name' => $payload['FamilyName'] ?? null,
            'title' => $payload['Title'] ?? null,
            'suffix' => $payload['Suffix'] ?? null,
            'email' => Arr::get($payload, 'PrimaryEmailAddr.Address'),
            'phone' => Arr::get($payload, 'PrimaryPhone.FreeFormNumber'),
            'alternate_phone' => Arr::get($payload, 'AlternatePhone.FreeFormNumber'),
            'mobile_phone' => Arr::get($payload, 'Mobile.FreeFormNumber'),
            'fax' => Arr::get($payload, 'Fax.FreeFormNumber'),
            'website' => Arr::get($payload, 'WebAddr.URI'),
            'address' => $this->formatAddress(Arr::get($payload, 'BillAddr')),
            'notes' => $payload['Notes'] ?? null,
            'quickbooks_sync_token' => $payload['SyncToken'] ?? null,
            'quickbooks_active' => $payload['Active'] ?? null,
            'quickbooks_parent_id' => $this->referenceId($payload, 'ParentRef'),
            'quickbooks_level' => $payload['Level'] ?? null,
            'quickbooks_is_job' => $payload['Job'] ?? false,
            'quickbooks_bill_with_parent' => $payload['BillWithParent'] ?? null,
            'quickbooks_is_project' => $payload['IsProject'] ?? null,
            'quickbooks_fully_qualified_name' => $payload['FullyQualifiedName'] ?? null,
            'quickbooks_print_on_check_name' => $payload['PrintOnCheckName'] ?? null,
            'quickbooks_customer_type_id' => $this->referenceId($payload, 'CustomerTypeRef'),
            'quickbooks_customer_type_name' => Arr::get($payload, 'CustomerTypeRef.name'),
            'quickbooks_custom_fields' => $payload['CustomField'] ?? null,
            'preferred_delivery_method' => $payload['PreferredDeliveryMethod'] ?? null,
            'quickbooks_created_at' => $this->parseTimestamp(Arr::get($payload, 'MetaData.CreateTime')),
            'quickbooks_updated_at' => $this->parseTimestamp(Arr::get($payload, 'MetaData.LastUpdatedTime')),
        ])->save();
    }

    private function syncAddress(Customer $customer, string $addressType, mixed $address, bool $authoritative = false): void
    {
        if (! is_array($address) && ! $authoritative) {
            return;
        }

        $address = is_array($address) ? $address : [];
        $hasAddress = collect($address)
            ->except(['Id', 'Lat', 'Long'])
            ->contains(fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        if ($hasAddress) {
            $incomingAddress = [
                'line1' => $address['Line1'] ?? null,
                'line2' => $address['Line2'] ?? null,
                'line3' => $address['Line3'] ?? null,
                'line4' => $address['Line4'] ?? null,
                'line5' => $address['Line5'] ?? null,
                'city' => $address['City'] ?? null,
                'country_subdivision_code' => $address['CountrySubDivisionCode'] ?? null,
                'postal_code' => $address['PostalCode'] ?? null,
                'country' => $address['Country'] ?? null,
            ];
            $matchingLocalAddress = CustomerAddress::query()
                ->where('customer_id', $customer->getKey())
                ->where('address_type', $addressType)
                ->whereIn('source', [CustomerAddress::SOURCE_MANUAL, CustomerAddress::SOURCE_SITE])
                ->get()
                ->first(fn (CustomerAddress $candidate): bool => $this->addressFields($candidate) === $incomingAddress);

            if ($matchingLocalAddress !== null) {
                $attributes = [
                    'quickbooks_address_id' => $address['Id'] ?? null,
                ];

                if ($authoritative) {
                    CustomerAddress::query()
                        ->where('customer_id', $customer->getKey())
                        ->where('address_type', $addressType)
                        ->where('is_default', true)
                        ->where('id', '!=', $matchingLocalAddress->getKey())
                        ->update(['is_default' => false]);
                    $attributes['is_default'] = true;
                }

                $matchingLocalAddress->forceFill($attributes)->save();

                return;
            }
        }

        $customerAddress = CustomerAddress::query()
            ->where('customer_id', $customer->getKey())
            ->where('address_type', $addressType)
            ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
            ->first();

        if ($customerAddress === null && CustomerAddress::withArchived()
            ->where('customer_id', $customer->getKey())
            ->where('address_type', $addressType)
            ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
            ->where('is_archived', true)
            ->exists()) {
            return;
        }

        if (! $hasAddress && $customerAddress === null) {
            if ($authoritative) {
                CustomerAddress::query()
                    ->where('customer_id', $customer->getKey())
                    ->where('address_type', $addressType)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return;
        }

        $customerAddress ??= CustomerAddress::query()->firstOrNew([
            'customer_id' => $customer->getKey(),
            'address_type' => $addressType,
            'source' => CustomerAddress::SOURCE_QUICKBOOKS,
        ]);
        if ($authoritative) {
            $defaults = CustomerAddress::query()
                ->where('customer_id', $customer->getKey())
                ->where('address_type', $addressType)
                ->where('is_default', true);

            if ($customerAddress->exists) {
                $defaults->where('id', '!=', $customerAddress->getKey());
            }

            $defaults->update(['is_default' => false]);
            $customerAddress->is_default = $hasAddress;
        } else {
            $existingDefault = CustomerAddress::query()
                ->where('customer_id', $customer->getKey())
                ->where('address_type', $addressType)
                ->where('is_default', true)
                ->first();
            $keepExistingDefault = $existingDefault !== null
                && $customerAddress->exists
                && $existingDefault->is($customerAddress);

            $customerAddress->is_default = $hasAddress && ($existingDefault === null || $keepExistingDefault);
        }

        $customerAddress->fill([
            'source_site_id' => null,
            'quickbooks_address_id' => $address['Id'] ?? null,
            'label' => null,
            'line1' => $address['Line1'] ?? null,
            'line2' => $address['Line2'] ?? null,
            'line3' => $address['Line3'] ?? null,
            'line4' => $address['Line4'] ?? null,
            'line5' => $address['Line5'] ?? null,
            'city' => $address['City'] ?? null,
            'country_subdivision_code' => $address['CountrySubDivisionCode'] ?? null,
            'postal_code' => $address['PostalCode'] ?? null,
            'country' => $address['Country'] ?? null,
            'latitude' => $address['Lat'] ?? null,
            'longitude' => $address['Long'] ?? null,
        ])->save();
    }

    private function syncSite(
        Customer $customer,
        array $payload,
        string $quickBooksId,
        string $parentId,
        ?Site $existingSite = null,
        bool $authoritative = false,
    ): void {
        $site = $existingSite ?? Site::withArchived()->firstOrNew(['quickbooks_id' => $quickBooksId]);

        if ($site->exists && $site->is_archived) {
            return;
        }
        $site->forceFill([
            'customer_id' => $customer->getKey(),
            'name' => $payload['DisplayName'] ?? 'QuickBooks site '.$quickBooksId,
            'company_name' => $payload['CompanyName'] ?? null,
            'preferred_delivery_method' => $payload['PreferredDeliveryMethod'] ?? null,
            'address' => $this->formatAddress(Arr::get($payload, 'ShipAddr') ?: Arr::get($payload, 'BillAddr')),
            'quickbooks_sync_token' => $payload['SyncToken'] ?? null,
            'quickbooks_active' => $payload['Active'] ?? null,
            'quickbooks_parent_id' => $parentId,
            'quickbooks_level' => $payload['Level'] ?? null,
            'quickbooks_is_job' => $payload['Job'] ?? null,
            'quickbooks_bill_with_parent' => $payload['BillWithParent'] ?? null,
            'quickbooks_is_project' => $payload['IsProject'] ?? null,
            'quickbooks_fully_qualified_name' => $payload['FullyQualifiedName'] ?? null,
            'quickbooks_customer_type_id' => $this->referenceId($payload, 'CustomerTypeRef'),
            'quickbooks_customer_type_name' => Arr::get($payload, 'CustomerTypeRef.name'),
            'quickbooks_custom_fields' => $payload['CustomField'] ?? null,
            'quickbooks_print_on_check_name' => $payload['PrintOnCheckName'] ?? null,
            'quickbooks_created_at' => $this->parseTimestamp(Arr::get($payload, 'MetaData.CreateTime')),
            'quickbooks_updated_at' => $this->parseTimestamp(Arr::get($payload, 'MetaData.LastUpdatedTime')),
        ])->save();

        $this->syncSiteAddress($customer, $site, CustomerAddress::TYPE_BILLING, Arr::get($payload, 'BillAddr'));
        $this->syncSiteAddress($customer, $site, CustomerAddress::TYPE_SHIPPING, Arr::get($payload, 'ShipAddr'));

        $contactAttributes = [
            'source' => 'quickbooks',
            'first_name' => $payload['GivenName'] ?? null,
            'middle_name' => $payload['MiddleName'] ?? null,
            'last_name' => $payload['FamilyName'] ?? null,
            'title' => $payload['Title'] ?? null,
            'suffix' => $payload['Suffix'] ?? null,
            'email' => Arr::get($payload, 'PrimaryEmailAddr.Address'),
            'phone' => Arr::get($payload, 'PrimaryPhone.FreeFormNumber'),
            'alternate_phone' => Arr::get($payload, 'AlternatePhone.FreeFormNumber'),
            'mobile_phone' => Arr::get($payload, 'Mobile.FreeFormNumber'),
            'fax' => Arr::get($payload, 'Fax.FreeFormNumber'),
            'website' => Arr::get($payload, 'WebAddr.URI'),
        ];
        $hasContactDetails = collect($contactAttributes)
            ->except('source')
            ->contains(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $contact = Contact::query()->where([
            'customer_id' => $customer->getKey(),
            'site_id' => $site->getKey(),
            'contact_type' => 'primary',
        ])->first();

        if ($contact === null && Contact::withArchived()->where([
            'customer_id' => $customer->getKey(),
            'site_id' => $site->getKey(),
            'contact_type' => 'primary',
            'is_archived' => true,
        ])->exists()) {
            return;
        }

        if (! $hasContactDetails && ($contact === null || ! $authoritative)) {
            return;
        }

        $contact ??= new Contact([
            'customer_id' => $customer->getKey(),
            'site_id' => $site->getKey(),
            'contact_type' => 'primary',
        ]);
        $contact->fill($contactAttributes)->save();
    }

    private function syncSiteAddress(Customer $customer, Site $site, string $addressType, mixed $address): void
    {
        $address = is_array($address) ? $address : [];
        $hasAddress = collect($address)
            ->except(['Id', 'Lat', 'Long'])
            ->contains(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $customerAddress = CustomerAddress::query()
            ->where('customer_id', $customer->getKey())
            ->where('source_site_id', $site->getKey())
            ->where('address_type', $addressType)
            ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
            ->first();

        if ($customerAddress === null && CustomerAddress::withArchived()
            ->where('customer_id', $customer->getKey())
            ->where('source_site_id', $site->getKey())
            ->where('address_type', $addressType)
            ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
            ->where('is_archived', true)
            ->exists()) {
            return;
        }

        if (! $hasAddress && $customerAddress === null) {
            return;
        }

        $customerAddress ??= new CustomerAddress;
        $customerAddress->fill([
            'customer_id' => $customer->getKey(),
            'source_site_id' => $site->getKey(),
            'address_type' => $addressType,
            'source' => CustomerAddress::SOURCE_QUICKBOOKS,
            'quickbooks_address_id' => $address['Id'] ?? null,
            'line1' => $address['Line1'] ?? null,
            'line2' => $address['Line2'] ?? null,
            'line3' => $address['Line3'] ?? null,
            'line4' => $address['Line4'] ?? null,
            'line5' => $address['Line5'] ?? null,
            'city' => $address['City'] ?? null,
            'country_subdivision_code' => $address['CountrySubDivisionCode'] ?? null,
            'postal_code' => $address['PostalCode'] ?? null,
            'country' => $address['Country'] ?? null,
            'latitude' => $address['Lat'] ?? null,
            'longitude' => $address['Long'] ?? null,
            'is_default' => false,
        ])->save();
    }

    private function referenceId(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key.'.value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function formatAddress(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $street = collect(range(1, 5))
            ->map(fn (int $line): ?string => $address['Line'.$line] ?? null)
            ->filter(fn (?string $line): bool => is_string($line) && trim($line) !== '')
            ->values()
            ->all();
        $locality = collect([
            $address['City'] ?? null,
            $address['CountrySubDivisionCode'] ?? null,
            $address['PostalCode'] ?? null,
        ])->filter(fn (?string $part): bool => is_string($part) && trim($part) !== '')
            ->implode(' ');

        if ($locality !== '') {
            $street[] = $locality;
        }

        if (is_string($address['Country'] ?? null) && trim($address['Country']) !== '') {
            $street[] = $address['Country'];
        }

        return $street === [] ? null : implode(', ', $street);
    }

    /** @return array<string, mixed> */
    private function addressFields(CustomerAddress $address): array
    {
        return [
            'line1' => $address->line1,
            'line2' => $address->line2,
            'line3' => $address->line3,
            'line4' => $address->line4,
            'line5' => $address->line5,
            'city' => $address->city,
            'country_subdivision_code' => $address->country_subdivision_code,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
        ];
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
