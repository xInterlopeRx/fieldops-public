<?php

namespace App\Services\FieldPulse;

use RuntimeException;

/**
 * Maps a FieldPulse API record to FieldOps fields (ADR-0039).
 *
 * Pure transformation: no persistence, no provider calls. Provider-specific
 * attributes that have no canonical FieldOps column are preserved under a
 * `fieldpulse` metadata fragment rather than discarded.
 */
class FieldPulseCustomerMapper
{
    /**
     * @param  array<string, mixed>  $record
     * @return array{
     *     source_record_id: string,
     *     customer: array<string, mixed>,
     *     primary_contact: array<string, string|null>,
     *     secondary_contact: array<string, string|null>
     * }
     */
    public function customer(array $record): array
    {
        $sourceId = $this->scalarId($record['id'] ?? null);

        if ($sourceId === null) {
            throw new RuntimeException('fieldpulse_customer_id_missing');
        }

        $name = $this->string($record['display_name'] ?? null)
            ?? $this->string($record['company_name'] ?? null)
            ?? $this->joinedName($record['first_name'] ?? null, $record['last_name'] ?? null);

        if ($name === null) {
            throw new RuntimeException('fieldpulse_customer_name_missing');
        }

        $address = $this->composeAddress($record, 'address_1', 'address_2', 'city', 'state', 'zip_code');

        $customer = [
            'name' => $name,
            'company_name' => $this->string($record['company_name'] ?? null),
            'first_name' => $this->string($record['first_name'] ?? null),
            'last_name' => $this->string($record['last_name'] ?? null),
            'email' => $this->string($record['email'] ?? null) ?? $this->string($record['alt_email'] ?? null),
            'phone' => $this->string($record['phone'] ?? null),
            'alternate_phone' => $this->string($record['alt_phone'] ?? null),
            'mobile_phone' => $this->string($record['mobile_phone'] ?? null),
            'fax' => $this->string($record['fax'] ?? null),
            'website' => $this->string($record['website'] ?? null),
            'address' => $address,
            'notes' => $this->string($record['notes'] ?? null),
        ];

        $metadata = array_filter([
            'account_type' => $this->string($record['account_type'] ?? null),
            'status' => $this->string($record['status'] ?? null),
            'lead_source' => $this->string($record['lead_source'] ?? null),
            'assigned_to' => $this->scalarId($record['assigned_to'] ?? null),
            'created_at' => $this->string($record['created_at'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'source_record_id' => $sourceId,
            'customer' => [...$customer, 'metadata' => $metadata],
            'primary_contact' => array_filter([
                'first_name' => $this->string($record['first_name'] ?? null),
                'last_name' => $this->string($record['last_name'] ?? null),
                'email' => $this->string($record['email'] ?? null),
                'alternate_email' => $this->string($record['alt_email'] ?? null),
                'phone' => $this->string($record['phone'] ?? null),
                'alternate_phone' => $this->string($record['alt_phone'] ?? null),
                'mobile_phone' => $this->string($record['mobile_phone'] ?? null),
            ], static fn (?string $value): bool => $value !== null),
            'secondary_contact' => array_filter([
                'first_name' => $this->string($record['secondary_first_name'] ?? null),
                'last_name' => $this->string($record['secondary_last_name'] ?? null),
                'email' => $this->string($record['secondary_email'] ?? null),
                'phone' => $this->string($record['secondary_phone'] ?? null),
            ], static fn (?string $value): bool => $value !== null),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{source_record_id: string, customer_id: string, site: array<string, mixed>}
     */
    public function location(array $record): array
    {
        $sourceId = $this->scalarId($record['id'] ?? null);
        $objectId = $this->scalarId($record['object_id'] ?? null);
        $objectType = $this->string($record['object_type'] ?? null);

        if ($sourceId === null) {
            throw new RuntimeException('fieldpulse_location_id_missing');
        }

        // The provider reports object_type as a fully-qualified model name
        // (e.g. App\Http\Api\Core\Models\BaseCustomer), not the literal "customer".
        if ($objectType === null || stripos($objectType, 'customer') === false || $objectId === null) {
            throw new RuntimeException('fieldpulse_location_owner_invalid');
        }

        $name = $this->string($record['title'] ?? null) ?? 'Location';
        $address = $this->composeAddress($record, 'address_1', 'address_2', 'city', 'state', 'zip_code');

        return [
            'source_record_id' => $sourceId,
            'customer_id' => $objectId,
            'site' => [
                'name' => $name,
                'address' => $address,
                'metadata' => array_filter([
                    'is_main_location' => $this->boolean($record['is_main_location'] ?? null),
                    'is_primary_location' => $this->boolean($record['is_primary_location'] ?? null),
                    'latitude' => $this->string($record['location_coords']['latitude'] ?? null),
                    'longitude' => $this->string($record['location_coords']['longitude'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== false),
            ],
        ];
    }

    /** @param array<string, mixed> $record */
    private function composeAddress(array $record, string ...$keys): ?string
    {
        $parts = [];

        foreach ($keys as $key) {
            $value = $this->string($record[$key] ?? null);

            if ($value !== null) {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function joinedName(mixed $first, mixed $last): ?string
    {
        $first = $this->string($first);
        $last = $this->string($last);
        $joined = trim(implode(' ', array_filter([$first, $last])));

        return $joined === '' ? null : $joined;
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function scalarId(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function boolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
