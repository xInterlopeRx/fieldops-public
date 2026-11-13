<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        $now = now();
        $roles = ['finance', 'hr'];
        $permissions = [
            'integrations.view_logs',
            'time_entries.view_team',
            'time_entries.view_all',
            'time_entries.view_financial',
            'time_entries.view_exceptions',
            'time_entries.review',
            'time_entries.billing.manage',
        ];

        $claim = DB::table('installation_claims')->where('id', 1)->first(['claimed_at']);
        $activeAdminCount = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('roles.name', 'admin')
            ->where('roles.guard_name', $guard)
            ->where('model_has_roles.model_type', User::class)
            ->where('users.status', 'active')
            ->distinct()
            ->count('users.id');
        $adminAccountCount = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'admin')
            ->where('roles.guard_name', $guard)
            ->where('model_has_roles.model_type', User::class)
            ->distinct()
            ->count('model_has_roles.model_id');

        if ($adminAccountCount > 1 || ($claim?->claimed_at !== null && $activeAdminCount !== 1)) {
            throw new RuntimeException('Resolve the Admin account count before installing the Finance/HR security groups.');
        }

        DB::transaction(function () use ($guard, $now, $roles, $permissions): void {
            foreach ($roles as $name) {
                DB::table('roles')->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($permissions as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $grants = [
                'admin' => ['integrations.view_logs', 'time_entries.view_team'],
                'basic_user' => ['time_entries.view_team'],
                'finance' => [
                    'admin.access',
                    'integrations.manage',
                    'integrations.view_logs',
                    'work_orders.view',
                    'work_orders.manage',
                    'time_entries.view_team',
                    'time_entries.view_all',
                    'time_entries.view_financial',
                    'time_entries.view_exceptions',
                    'time_entries.billing.manage',
                ],
                'hr' => [
                    'admin.access',
                    'integrations.manage',
                    'integrations.view_logs',
                    'users.manage',
                    'work_orders.view',
                    'work_orders.manage',
                    'time_entries.view_team',
                    'time_entries.view_all',
                    'time_entries.view_financial',
                    'time_entries.view_exceptions',
                    'time_entries.review',
                    'time_entries.billing.manage',
                ],
            ];

            foreach ($grants as $roleName => $permissionNames) {
                $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');

                foreach ($permissionNames as $permissionName) {
                    $permissionId = DB::table('permissions')->where('name', $permissionName)->where('guard_name', $guard)->value('id');
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }

            $adminRoleId = DB::table('roles')->where('name', 'admin')->where('guard_name', $guard)->value('id');
            $usersManagePermissionId = DB::table('permissions')
                ->where('name', 'users.manage')
                ->where('guard_name', $guard)
                ->value('id');
            DB::table('role_has_permissions')
                ->where('role_id', $adminRoleId)
                ->where('permission_id', $usersManagePermissionId)
                ->delete();

            $adminUserIds = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', 'admin')
                ->where('roles.guard_name', $guard)
                ->where('model_has_roles.model_type', User::class)
                ->pluck('model_has_roles.model_id');

            $financeRoleId = DB::table('roles')->where('name', 'finance')->where('guard_name', $guard)->value('id');
            $hrRoleId = DB::table('roles')->where('name', 'hr')->where('guard_name', $guard)->value('id');

            foreach ($adminUserIds as $userId) {
                foreach ([$financeRoleId, $hrRoleId] as $roleId) {
                    DB::table('model_has_roles')->insertOrIgnore([
                        'role_id' => $roleId,
                        'model_type' => User::class,
                        'model_id' => $userId,
                    ]);
                }
            }

            $claimantId = DB::table('installation_claims')->where('id', 1)->value('claimed_by_user_id');

            if ($claimantId !== null) {
                foreach ([$financeRoleId, $hrRoleId] as $roleId) {
                    DB::table('model_has_roles')->insertOrIgnore([
                        'role_id' => $roleId,
                        'model_type' => User::class,
                        'model_id' => $claimantId,
                    ]);
                }
            }

            foreach (DB::table('user_invitations')->get(['id', 'roles']) as $invitation) {
                $invitationRoles = is_array($invitation->roles)
                    ? $invitation->roles
                    : json_decode((string) $invitation->roles, true);

                if (! is_array($invitationRoles) || ! in_array('admin', $invitationRoles, true)) {
                    continue;
                }

                $invitationRoles = array_values(array_unique(array_filter([
                    ...array_diff($invitationRoles, ['admin']),
                    'basic_user',
                    'finance',
                    'hr',
                ])));
                DB::table('user_invitations')->where('id', $invitation->id)->update([
                    'roles' => json_encode($invitationRoles, JSON_THROW_ON_ERROR),
                ]);
            }
        });

        app('cache')->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Security-group assignments and role transfers are not safely reversible. Deploy a forward migration instead.',
        );
    }
};
