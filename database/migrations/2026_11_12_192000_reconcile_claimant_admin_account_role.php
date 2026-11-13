<?php

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $claim = DB::table('installation_claims')
                ->where('id', 1)
                ->lockForUpdate()
                ->first(['claimed_at', 'claimed_by_user_id']);
            $adminRoleId = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');

            if (! is_numeric($adminRoleId)) {
                throw new RuntimeException('The Admin account role must exist before reconciling duplicate assignments.');
            }

            $adminUserIds = DB::table('model_has_roles')
                ->where('role_id', (int) $adminRoleId)
                ->where('model_type', User::class)
                ->orderBy('model_id')
                ->lockForUpdate()
                ->pluck('model_id')
                ->map(static fn ($id): int => (int) $id);

            if ($adminUserIds->count() <= 1) {
                return;
            }

            if ($adminUserIds->count() !== 2 || $claim?->claimed_at === null || $claim->claimed_by_user_id === null) {
                throw new RuntimeException('Resolve unexpected duplicate Admin state before reconciling the claimant account.');
            }

            $claimantId = (int) $claim->claimed_by_user_id;

            if (! $adminUserIds->contains($claimantId)) {
                throw new RuntimeException('The installation claimant must retain the Admin account role during reconciliation.');
            }

            $claimant = User::query()->lockForUpdate()->findOrFail($claimantId);

            if ($claimant->status !== 'active' || ! $claimant->hasRole('admin')) {
                throw new RuntimeException('The installation claimant must be an active Admin before reconciliation.');
            }

            $otherAdminId = $adminUserIds->first(static fn (int $id): bool => $id !== $claimantId);

            if ($otherAdminId === null) {
                throw new RuntimeException('Could not identify the non-claimant Admin account for reconciliation.');
            }

            $otherAdmin = User::query()->lockForUpdate()->findOrFail($otherAdminId);
            $otherAdmin->removeRole('admin');

            if (! $otherAdmin->hasRole('basic_user')) {
                $otherAdmin->assignRole('basic_user');
            }

            app(ActivityLogService::class)->record($otherAdmin, 'admin_role_reconciled', [
                'previous_account_role' => 'admin',
                'new_account_role' => 'basic_user',
                'reason' => 'operator_approved_claimant_admin_reconciliation',
            ], $claimant);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Admin account-role reconciliation is not safely reversible; use an authorized forward change.');
    }
};
