<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\SharePointAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

class RetireSharePointAttachmentPointersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retire_only_selected_local_pointers_after_recording_external_reconciliation(): void
    {
        Http::fake();
        $admin = $this->adminWithTwoFactor();
        $customer = Customer::query()->create(['name' => 'Pointer test customer']);
        $selected = SharePointAttachment::query()->create($this->pointerAttributes($customer->getKey(), 'selected.pdf'));
        $preserved = SharePointAttachment::query()->create($this->pointerAttributes($customer->getKey(), 'preserved.pdf', 'item-2'));

        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.sharepoint-pointers.retire'), [
                'attachment_ids' => [$selected->getKey()],
                'remote_files_preserved' => true,
                'sharepoint_admin_upn' => 'sharepoint.admin@example.test',
                'verification_reference' => 'CHG-2026-1042',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'retired')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('message', '1 local SharePoint metadata pointer(s) retired. No SharePoint request was made and no remote file was deleted.');

        $this->assertDatabaseMissing('sharepoint_attachments', ['id' => $selected->getKey()]);
        $this->assertDatabaseHas('sharepoint_attachments', ['id' => $preserved->getKey()]);
        $event = AuditEvent::query()
            ->where('event', 'sharepoint_attachment_pointer_retired')
            ->sole();
        $this->assertSame($admin->getKey(), $event->actor_user_id);
        $this->assertSame(SharePointAttachment::class, $event->subject_type);
        $this->assertSame($selected->getKey(), $event->subject_id);
        $this->assertSame(true, $event->metadata['local_pointer_only']);
        $this->assertSame(false, $event->metadata['sharepoint_api_called']);
        $this->assertSame(false, $event->metadata['remote_file_deleted']);
        $this->assertSame('sharepoint.admin@example.test', $event->metadata['sharepoint_admin_upn']);
        $this->assertSame('CHG-2026-1042', $event->metadata['verification_reference']);
        Http::assertNothingSent();
    }

    public function test_pointer_retirement_requires_external_confirmation_and_fresh_password(): void
    {
        $admin = $this->adminWithTwoFactor();
        $customer = Customer::query()->create(['name' => 'Blocked pointer customer']);
        $pointer = SharePointAttachment::query()->create($this->pointerAttributes($customer->getKey()));

        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.sharepoint-pointers.retire'), [
                'attachment_ids' => [$pointer->getKey()],
                'remote_files_preserved' => false,
                'sharepoint_admin_upn' => 'sharepoint.admin@example.test',
                'verification_reference' => 'CHG-2026-1042',
                'password' => 'wrong-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remote_files_preserved']);

        $this->postJson(route('admin.sync-logs.sharepoint-pointers.retire'), [
            'attachment_ids' => [$pointer->getKey()],
            'remote_files_preserved' => true,
            'sharepoint_admin_upn' => 'sharepoint.admin@example.test',
            'verification_reference' => 'CHG-2026-1042',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseHas('sharepoint_attachments', ['id' => $pointer->getKey()]);
        $this->assertDatabaseMissing('audit_events', ['event' => 'sharepoint_attachment_pointer_retired']);
    }

    /** @return array<string, mixed> */
    private function pointerAttributes(int $customerId, string $filename = 'pointer.pdf', string $itemId = 'item-1'): array
    {
        return [
            'company_id' => $customerId,
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'file_size' => 123,
            'sharepoint_drive_id' => 'drive-1',
            'sharepoint_item_id' => $itemId,
            'sharepoint_url' => 'https://example.test/document',
        ];
    }

    private function adminWithTwoFactor(): User
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Pointer Admin',
            'status' => 'active',
        ]);
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin->fresh();
    }
}
