<?php

namespace Tests\Feature;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use App\Models\Contact;
use App\Models\ContactMethod;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContactMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_can_store_multiple_emails_phones_and_a_teams_method(): void
    {
        $contact = Contact::factory()->create();
        $contact->methods()->createMany([
            [
                'method_type' => ContactMethodType::Email,
                'label' => ContactMethodLabel::Office,
                'value' => 'office@example.test',
            ],
            [
                'method_type' => ContactMethodType::Email,
                'label' => ContactMethodLabel::Personal,
                'value' => 'personal@example.test',
            ],
            [
                'method_type' => ContactMethodType::Phone,
                'label' => ContactMethodLabel::Mobile,
                'value' => '+1-555-0100',
            ],
            [
                'method_type' => ContactMethodType::Phone,
                'label' => ContactMethodLabel::AfterHours,
                'value' => '+1-555-0199',
            ],
            [
                'method_type' => ContactMethodType::Phone,
                'label' => ContactMethodLabel::Dispatch,
                'value' => '+1-555-0101',
            ],
            [
                'method_type' => ContactMethodType::Phone,
                'label' => ContactMethodLabel::Operations,
                'value' => '+1-555-0102',
            ],
            [
                'method_type' => ContactMethodType::Teams,
                'label' => ContactMethodLabel::Office,
                'value' => 'fieldops.dispatch',
            ],
        ]);

        $contact->load('methods');

        $this->assertCount(7, $contact->methods);
        $this->assertSame(2, $contact->methods->where('method_type', ContactMethodType::Email)->count());
        $this->assertSame(4, $contact->methods->where('method_type', ContactMethodType::Phone)->count());
        $this->assertSame(
            ContactMethodLabel::AfterHours,
            $contact->methods->firstWhere('value', '+1-555-0199')->label,
        );
        $teamsMethod = $contact->methods->firstWhere('method_type', ContactMethodType::Teams);
        $this->assertSame('fieldops.dispatch', $teamsMethod->value);
        $this->assertSame($contact->getKey(), $teamsMethod->contact->getKey());
        $this->assertDatabaseHas('contact_methods', [
            'contact_id' => $contact->getKey(),
            'method_type' => 'teams',
            'label' => 'office',
            'value' => 'fieldops.dispatch',
        ]);
    }

    public function test_contact_and_contact_method_factories_build_the_relationship(): void
    {
        $contact = Contact::factory()->withMethods(3)->create();

        $this->assertCount(3, $contact->methods);

        $method = ContactMethod::factory()->create(['contact_id' => $contact->getKey()]);

        $this->assertModelExists($contact);
        $this->assertSame($contact->getKey(), $method->contact->getKey());
        $this->assertInstanceOf(ContactMethodType::class, $method->method_type);
        $this->assertInstanceOf(ContactMethodLabel::class, $method->label);
    }

    public function test_database_rejects_unsupported_contact_method_types(): void
    {
        $method = ContactMethod::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('contact_methods')
            ->where('id', $method->getKey())
            ->update(['method_type' => 'outlook']);
    }

    public function test_database_rejects_unsupported_contact_method_labels(): void
    {
        $method = ContactMethod::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('contact_methods')
            ->where('id', $method->getKey())
            ->update(['label' => 'headquarters']);
    }

    public function test_database_allows_only_one_primary_per_contact_and_method_type(): void
    {
        $contact = Contact::factory()->create();
        $contact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Office,
            'value' => 'one@example.test',
            'is_primary' => true,
        ]);
        $contact->methods()->create([
            'method_type' => ContactMethodType::Phone,
            'label' => ContactMethodLabel::Mobile,
            'value' => '+1-555-0100',
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        $contact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Personal,
            'value' => 'two@example.test',
            'is_primary' => true,
        ]);
    }

    public function test_database_accepts_a_primary_method_for_each_method_type(): void
    {
        $contact = Contact::factory()->create();

        foreach (ContactMethodType::cases() as $index => $type) {
            $contact->methods()->create([
                'method_type' => $type,
                'label' => ContactMethodLabel::Office,
                'value' => "method-{$index}",
                'is_primary' => true,
            ]);
        }

        $this->assertSame(3, $contact->methods()->where('is_primary', true)->count());
    }

    public function test_contact_methods_prevent_physical_deletion_of_an_archive_managed_contact(): void
    {
        $contact = Contact::factory()->withMethods()->create();

        $this->expectException(QueryException::class);

        DB::table('contacts')->where('id', $contact->getKey())->delete();
    }

    public function test_contact_methods_remain_available_when_the_contact_is_archived_and_restored(): void
    {
        $admin = User::factory()->admin()->create();
        $contact = Contact::factory()->withMethods(2)->create();
        $methodIds = $contact->methods()->orderBy('id')->pluck('id')->all();

        $batch = app(ArchiveService::class)->archive('contacts', $contact->getKey(), $admin);
        $this->assertDatabaseCount('contact_methods', 2);

        app(ArchiveService::class)->restore($batch->getKey(), $admin);

        $this->assertSame($methodIds, $contact->fresh()->methods()->orderBy('id')->pluck('id')->all());
    }
}
