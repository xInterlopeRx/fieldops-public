<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'model_has_roles_single_admin_user_unique';

    public function up(): void
    {
        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        if (! is_numeric($adminRoleId)) {
            throw new RuntimeException('The Admin account role must exist before enforcing its singleton constraint.');
        }

        $adminRoleId = (int) $adminRoleId;
        $adminCount = DB::table('model_has_roles')
            ->where('role_id', $adminRoleId)
            ->where('model_type', User::class)
            ->count();

        if ($adminCount > 1) {
            throw new RuntimeException('Resolve duplicate Admin account-role assignments before installing the singleton constraint.');
        }

        $quotedModelType = DB::connection()->getPdo()->quote(User::class);

        if (! is_string($quotedModelType)) {
            throw new RuntimeException('Could not quote the Admin model type for its singleton constraint.');
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON model_has_roles (role_id) WHERE role_id = %d AND model_type = %s',
            self::INDEX,
            $adminRoleId,
            $quotedModelType,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
