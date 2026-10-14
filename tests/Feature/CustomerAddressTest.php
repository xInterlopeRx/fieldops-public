<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_stores_quickbooks_profile_fields_and_sync_version(): void
    {
        $displayName = str_repeat('Q', 300);

        $customer = Customer::create([
            'name' => $displayName,
            'company_name' => 'Sandbox Company',
            'middle_name' => 'M',
            'quickbooks_sync_token' => '3',
            'quickbooks_active' => true,
            'quickbooks_is_job' => false,
            'quickbooks_customer_type_id' => 'type-3',
            'quickbooks_customer_type_name' => 'Commercial',
            'quickbooks_custom_fields' => [
                ['DefinitionId' => '1', 'Name' => 'Region', 'Type' => 'StringType', 'StringValue' => 'West'],
            ],
        ]);

        $customer->refresh();

        $this->assertSame($displayName, $customer->name);
        $this->assertSame('Sandbox Company', $customer->company_name);
        $this->assertSame('3', $customer->quickbooks_sync_token);
        $this->assertTrue($customer->quickbooks_active);
        $this->assertSame('type-3', $customer->quickbooks_customer_type_id);
        $this->assertSame('Commercial', $customer->quickbooks_customer_type_name);
        $this->assertSame('West', $customer->quickbooks_custom_fields[0]['StringValue']);
        $this->assertArrayNotHasKey('quickbooks_custom_fields', $customer->toArray());
        $this->assertArrayNotHasKey('quickbooks_sync_token', $customer->toArray());
    }

    public function test_customer_can_have_default_quickbooks_and_site_based_addresses(): void
    {
        $customer = Customer::create(['name' => 'Sandbox Company']);
        $site = $customer->sites()->create([
            'name' => 'Service Location',
            'address' => '44 Sample Road, Austin, TX 78701',
            'quickbooks_id' => 'qbo-job-8',
            'quickbooks_sync_token' => '0',
            'quickbooks_active' => true,
            'quickbooks_parent_id' => 'qbo-customer-7',
            'quickbooks_level' => 1,
            'quickbooks_is_job' => true,
            'quickbooks_bill_with_parent' => false,
            'quickbooks_is_project' => false,
            'quickbooks_fully_qualified_name' => 'Sandbox Company:Service Location',
            'quickbooks_customer_type_id' => 'type-4',
            'quickbooks_customer_type_name' => 'Residential',
            'quickbooks_custom_fields' => [
                ['DefinitionId' => '2', 'Name' => 'Territory', 'Type' => 'StringType', 'StringValue' => 'North'],
            ],
        ]);
        $siteContact = $customer->contacts()->create([
            'site_id' => $site->id,
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Sample',
            'last_name' => 'Contact',
            'email' => 'sample@example.test',
            'phone' => '555-0100',
            'mobile_phone' => '555-0101',
        ]);

        $billingAddress = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_QUICKBOOKS,
            'quickbooks_address_id' => 'billing-1',
            'line1' => '100 Billing Street',
            'city' => 'Austin',
            'country_subdivision_code' => 'TX',
            'postal_code' => '78701',
            'country' => 'USA',
            'is_default' => true,
        ]);
        $shippingAddress = $customer->addresses()->create([
            'source_site_id' => $site->id,
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            'label' => 'Default delivery location',
            'line1' => '44 Sample Road',
            'city' => 'Austin',
            'country_subdivision_code' => 'TX',
            'postal_code' => '78701',
            'country' => 'USA',
            'latitude' => 'INVALID',
            'longitude' => 'INVALID',
            'is_default' => true,
        ]);
        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'label' => 'Alternate warehouse',
            'line1' => '9 Supply Lane',
            'city' => 'Austin',
            'country_subdivision_code' => 'TX',
            'postal_code' => '78702',
            'country' => 'USA',
        ]);

        $customer->refresh();

        $this->assertSame($billingAddress->id, $customer->defaultBillingAddress->id);
        $this->assertSame($shippingAddress->id, $customer->defaultShippingAddress->id);
        $this->assertSame($site->id, $shippingAddress->sourceSite->id);
        $this->assertSame('qbo-job-8', $site->quickbooks_id);
        $this->assertSame('type-4', $site->quickbooks_customer_type_id);
        $this->assertSame('North', $site->quickbooks_custom_fields[0]['StringValue']);
        $this->assertSame($customer->id, $siteContact->customer_id);
        $this->assertSame($site->id, $siteContact->site->id);
        $this->assertSame('INVALID', $shippingAddress->latitude);
        $this->assertCount(3, $customer->addresses);
        $this->assertArrayNotHasKey('quickbooks_address_id', $billingAddress->toArray());
    }

    public function test_customer_cannot_have_two_default_addresses_of_the_same_type(): void
    {
        $customer = Customer::create(['name' => 'Sandbox Company']);

        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => '9 Supply Lane',
            'is_default' => true,
        ]);

        $this->expectException(QueryException::class);

        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            'line1' => '44 Sample Road',
            'is_default' => true,
        ]);
    }

    public function test_customer_can_have_a_primary_contact_for_each_site(): void
    {
        $customer = Customer::create(['name' => 'Sandbox Company']);
        $firstSite = $customer->sites()->create(['name' => 'First Location']);
        $secondSite = $customer->sites()->create(['name' => 'Second Location']);

        $firstContact = $customer->contacts()->create([
            'site_id' => $firstSite->id,
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'First',
        ]);
        $secondContact = $customer->contacts()->create([
            'site_id' => $secondSite->id,
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Second',
        ]);

        $this->assertSame($firstSite->id, $firstContact->site->id);
        $this->assertSame($secondSite->id, $secondContact->site->id);
        $this->assertCount(2, $customer->contacts);
    }
}
