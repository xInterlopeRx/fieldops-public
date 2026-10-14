<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_status_check');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check CHECK (status IN ('created', 'scheduled', 'pending', 'on_my_way', 'in_progress', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE work_orders ALTER COLUMN status SET DEFAULT 'created'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE work_orders SET status = 'pending' WHERE status = 'created'");
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_status_check');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check CHECK (status IN ('pending', 'scheduled', 'on_my_way', 'in_progress', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE work_orders ALTER COLUMN status SET DEFAULT 'pending'");
    }
};
