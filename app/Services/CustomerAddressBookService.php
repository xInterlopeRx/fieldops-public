<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

class CustomerAddressBookService
{
    /** @return array<string, string|null> */
    public function defaultsForForm(Customer $customer, string $addressType): array
    {
        $address = $this->defaultAddress($customer, $addressType);

        if ($address === null && $addressType === CustomerAddress::TYPE_BILLING && $customer->address) {
            return $this->emptyFields(['line1' => $customer->address]);
        }

        return $this->emptyFields($address?->only([
            'line1', 'line2', 'line3', 'line4', 'line5', 'city',
            'country_subdivision_code', 'postal_code', 'country',
        ]) ?? []);
    }

    /** @param array<string, mixed> $values */
    public function saveDefault(Customer $customer, string $addressType, array $values): bool
    {
        $fields = $this->emptyFields($values);
        $hasContent = collect($fields)->contains(fn (?string $value): bool => $value !== null);
        $existingDefault = $this->defaultAddress($customer, $addressType);

        if ($existingDefault !== null && $fields === $this->fieldsFromAddress($existingDefault)) {
            return false;
        }

        if (! $hasContent) {
            $hasQuickBooksAddress = $customer->quickbooks_id !== null && (
                $existingDefault?->quickbooks_address_id !== null || CustomerAddress::query()
                    ->where('customer_id', $customer->getKey())
                    ->where('address_type', $addressType)
                    ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
                    ->where(function ($query): void {
                        foreach (['line1', 'line2', 'line3', 'line4', 'line5', 'city', 'country_subdivision_code', 'postal_code', 'country'] as $field) {
                            $query->orWhereNotNull($field);
                        }
                    })
                    ->exists()
            );

            if ($hasQuickBooksAddress) {
                throw ValidationException::withMessages([
                    $addressType === CustomerAddress::TYPE_BILLING ? 'billing_address.line1' : 'shipping_address.line1' => 'QuickBooks does not support removing a default customer address through this integration. Replace it or remove it in QuickBooks.',
                ]);
            }

            if ($existingDefault !== null) {
                $existingDefault->forceFill(['is_default' => false])->save();
            }

            if ($addressType === CustomerAddress::TYPE_BILLING) {
                $customer->forceFill(['address' => null])->save();
            }

            return $existingDefault !== null;
        }

        $manualAddress = $customer->addresses()
            ->where('address_type', $addressType)
            ->where('source', CustomerAddress::SOURCE_MANUAL)
            ->first();
        $quickBooksAddressId = $manualAddress?->quickbooks_address_id
            ?? ($existingDefault?->source === CustomerAddress::SOURCE_QUICKBOOKS
                ? $existingDefault->quickbooks_address_id
                : null);

        CustomerAddress::query()
            ->where('customer_id', $customer->getKey())
            ->where('address_type', $addressType)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $manualAddress ??= new CustomerAddress([
            'customer_id' => $customer->getKey(),
            'address_type' => $addressType,
            'source' => CustomerAddress::SOURCE_MANUAL,
        ]);
        $manualAddress->forceFill([
            ...$fields,
            'source_site_id' => null,
            'quickbooks_address_id' => $quickBooksAddressId,
            'label' => null,
            'is_default' => true,
        ])->save();

        if ($addressType === CustomerAddress::TYPE_BILLING) {
            $customer->forceFill(['address' => $this->formatAddressFields($fields)])->save();
        }

        return true;
    }

    public function defaultAddress(Customer $customer, string $addressType): ?CustomerAddress
    {
        return $customer->addresses()
            ->where('address_type', $addressType)
            ->where('is_default', true)
            ->first();
    }

    /** @param array<string, mixed> $values
     * @return array<string, string|null>
     */
    private function emptyFields(array $values): array
    {
        $fields = [];

        foreach ([
            'line1', 'line2', 'line3', 'line4', 'line5', 'city',
            'country_subdivision_code', 'postal_code', 'country',
        ] as $field) {
            $value = $values[$field] ?? null;
            $fields[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $fields;
    }

    /** @return array<string, string|null> */
    private function fieldsFromAddress(CustomerAddress $address): array
    {
        return $this->emptyFields($address->only([
            'line1', 'line2', 'line3', 'line4', 'line5', 'city',
            'country_subdivision_code', 'postal_code', 'country',
        ]));
    }

    /** @return array<string, string|null> */
    public function siteAddressFields(Site $site): array
    {
        $shippingAddress = $this->siteAddress($site, CustomerAddress::TYPE_SHIPPING);
        $billingAddress = $this->siteAddress($site, CustomerAddress::TYPE_BILLING);
        $address = $shippingAddress !== null && $this->hasAddressContent($shippingAddress)
            ? $shippingAddress
            : ($billingAddress ?? $shippingAddress);

        if ($address === null) {
            return $this->emptyFields([
                'line1' => $site->address,
                'country' => 'US',
            ]);
        }

        return $this->emptyFields($address->only([
            'line1', 'line2', 'line3', 'line4', 'line5', 'city',
            'country_subdivision_code', 'postal_code', 'country',
        ]));
    }

    /** @param array<string, mixed> $values */
    public function saveSiteAddress(Site $site, array $values): bool
    {
        $fields = $this->emptyFields($values);
        $address = $this->siteAddress($site, CustomerAddress::TYPE_SHIPPING);

        if ($address !== null && $fields === $this->fieldsFromAddress($address)) {
            return false;
        }

        $isDefault = $address?->is_default ?? false;
        $address ??= new CustomerAddress;
        foreach (['line3', 'line4', 'line5'] as $preservedLine) {
            $fields[$preservedLine] = $address->getAttribute($preservedLine);
        }

        $address->fill([
            'customer_id' => $site->customer_id,
            'source_site_id' => $site->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => $address->quickbooks_address_id !== null
                ? CustomerAddress::SOURCE_QUICKBOOKS
                : CustomerAddress::SOURCE_SITE,
            'label' => null,
            'quickbooks_address_id' => $address->quickbooks_address_id,
            ...$fields,
            'is_default' => $isDefault,
        ])->save();

        return true;
    }

    public function siteAddressSummary(Site $site): string
    {
        $shippingAddress = $this->siteAddress($site, CustomerAddress::TYPE_SHIPPING);
        $billingAddress = $this->siteAddress($site, CustomerAddress::TYPE_BILLING);
        $address = $shippingAddress !== null && $this->hasAddressContent($shippingAddress)
            ? $shippingAddress
            : ($billingAddress ?? $shippingAddress);

        if ($address === null) {
            return $site->address ?? '';
        }

        $summary = $this->formatAddressFields($this->fieldsFromAddress($address));

        return $summary ?? $site->address ?? '';
    }

    /** @param array<string, string|null> $fields */
    public function formatAddressFields(array $fields): ?string
    {
        $street = array_values(array_filter([
            $fields['line1'], $fields['line2'], $fields['line3'], $fields['line4'], $fields['line5'],
        ]));
        $locality = implode(' ', array_filter([
            $fields['city'], $fields['country_subdivision_code'], $fields['postal_code'],
        ]));

        if ($locality !== '') {
            $street[] = $locality;
        }

        if ($fields['country'] !== null) {
            $street[] = $fields['country'];
        }

        return $street === [] ? null : implode(', ', $street);
    }

    private function siteAddress(Site $site, string $addressType): ?CustomerAddress
    {
        $addresses = $site->relationLoaded('customerAddresses')
            ? $site->customerAddresses
            : $site->customerAddresses()->get();

        return $addresses
            ->where('address_type', $addressType)
            ->sortBy(fn (CustomerAddress $address): array => [
                $this->hasAddressContent($address) ? 0 : 1,
                $address->quickbooks_address_id !== null ? 0 : ($address->source === CustomerAddress::SOURCE_SITE ? 1 : 2),
                (int) $address->getKey(),
            ])
            ->first();
    }

    private function hasAddressContent(CustomerAddress $address): bool
    {
        return collect($address->only([
            'line1', 'line2', 'line3', 'line4', 'line5', 'city',
            'country_subdivision_code', 'postal_code', 'country',
        ]))->contains(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }
}
