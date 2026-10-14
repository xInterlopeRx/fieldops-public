<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
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

    /** @param array<string, string|null> $fields */
    private function formatAddressFields(array $fields): ?string
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
}
