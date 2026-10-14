<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $guard = 'web';
        $roles = ['admin', 'basic_user', 'management', 'billing'];
        $permissions = [
            'admin.access',
            'users.manage',
            'integrations.manage',
            'work_orders.view',
            'work_orders.manage',
            'notifications.management',
            'notifications.billing',
        ];

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
            'admin' => ['admin.access', 'users.manage', 'integrations.manage', 'work_orders.view', 'work_orders.manage'],
            'basic_user' => ['work_orders.view', 'work_orders.manage'],
            'management' => ['notifications.management'],
            'billing' => ['notifications.billing'],
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

        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->json('roles')->nullable();
        });

        foreach (DB::table('users')->get(['id', 'role']) as $user) {
            $roleName = $user->role === 'admin' ? 'admin' : 'basic_user';
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $user->id,
            ]);
        }

        foreach (DB::table('user_invitations')->get(['id', 'role']) as $invitation) {
            $roleName = in_array($invitation->role, $roles, true) ? $invitation->role : 'basic_user';
            DB::table('user_invitations')
                ->where('id', $invitation->id)
                ->update(['roles' => json_encode([$roleName])]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });

        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->dropColumn('role');
        });

        app('cache')->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Down-migration preserves the one account role but cannot represent optional notification roles in the legacy scalar column.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('basic_user');
        });

        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->string('role')->default('basic_user');
        });

        foreach (DB::table('users')->get(['id']) as $user) {
            $isAdmin = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.model_id', $user->id)
                ->where('roles.name', 'admin')
                ->exists();

            DB::table('users')->where('id', $user->id)->update(['role' => $isAdmin ? 'admin' : 'basic_user']);
        }

        foreach (DB::table('user_invitations')->get(['id', 'roles']) as $invitation) {
            $roles = is_string($invitation->roles) ? json_decode($invitation->roles, true) : $invitation->roles;
            $roleName = is_array($roles) && in_array('admin', $roles, true) ? 'admin' : 'basic_user';
            DB::table('user_invitations')->where('id', $invitation->id)->update(['role' => $roleName]);
        }

        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->dropColumn('roles');
        });

        app('cache')->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
