<?php

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
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION reject_audit_event_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events are append-only' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql;
                SQL);
            DB::unprepared('CREATE TRIGGER audit_events_reject_update BEFORE UPDATE ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()');
            DB::unprepared('CREATE TRIGGER audit_events_reject_delete BEFORE DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()');
            DB::unprepared('CREATE TRIGGER audit_events_reject_truncate BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_event_mutation()');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER audit_events_reject_update BEFORE UPDATE ON audit_events BEGIN SELECT RAISE(ABORT, 'audit_events are append-only'); END");
            DB::unprepared("CREATE TRIGGER audit_events_reject_delete BEFORE DELETE ON audit_events BEGIN SELECT RAISE(ABORT, 'audit_events are append-only'); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $orphanedActors = DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_user_id')
            ->whereNotNull('audit_events.actor_user_id')
            ->whereNull('users.id')
            ->exists();

        if ($orphanedActors) {
            throw new RuntimeException('Audit actor history contains deleted users; refusing to restore the actor foreign key.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update ON audit_events');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_delete ON audit_events');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_truncate ON audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_audit_event_mutation()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_delete');
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
