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
        Schema::create('time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->restrictOnDelete();
            $table->enum('activity_type', [
                'travel',
                'labor',
                'remote_support',
                'documentation',
                'research',
                'meeting',
                'administrative',
                'project_work',
            ]);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('billable')->default(false);
            $table->text('notes')->nullable();
            $table->enum('approval_status', ['draft', 'approved', 'invoiced', 'paid'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['user_id', 'started_at'], 'time_entries_user_started_at_index');
            $table->index(['customer_id', 'started_at'], 'time_entries_customer_started_at_index');
            $table->index(['project_id', 'started_at'], 'time_entries_project_started_at_index');
            $table->index(['job_id', 'started_at'], 'time_entries_job_started_at_index');
            $table->index(['visit_id', 'started_at'], 'time_entries_visit_started_at_index');
            $table->index(['approval_status', 'started_at'], 'time_entries_status_started_at_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE time_entries
                ADD CONSTRAINT time_entries_valid_interval_check
                CHECK (
                    (ended_at IS NULL AND duration_minutes IS NULL AND approval_status = 'draft')
                    OR (
                        ended_at IS NOT NULL
                        AND duration_minutes IS NOT NULL
                        AND duration_minutes >= 0
                        AND ended_at > started_at
                    )
                )
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER time_entries_validate_interval_insert
                BEFORE INSERT ON time_entries
                FOR EACH ROW
                WHEN (NEW.ended_at IS NULL AND (NEW.duration_minutes IS NOT NULL OR NEW.approval_status != 'draft'))
                    OR (NEW.ended_at IS NOT NULL AND (
                        NEW.duration_minutes IS NULL
                        OR NEW.duration_minutes < 0
                        OR julianday(NEW.ended_at) IS NULL
                        OR julianday(NEW.started_at) IS NULL
                        OR julianday(NEW.ended_at) <= julianday(NEW.started_at)
                    ))
                BEGIN
                    SELECT RAISE(ABORT, 'time_entries_invalid_interval');
                END;
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER time_entries_validate_interval_update
                BEFORE UPDATE OF duration_minutes, started_at, ended_at, approval_status ON time_entries
                FOR EACH ROW
                WHEN (NEW.ended_at IS NULL AND (NEW.duration_minutes IS NOT NULL OR NEW.approval_status != 'draft'))
                    OR (NEW.ended_at IS NOT NULL AND (
                        NEW.duration_minutes IS NULL
                        OR NEW.duration_minutes < 0
                        OR julianday(NEW.ended_at) IS NULL
                        OR julianday(NEW.started_at) IS NULL
                        OR julianday(NEW.ended_at) <= julianday(NEW.started_at)
                    ))
                BEGIN
                    SELECT RAISE(ABORT, 'time_entries_invalid_interval');
                END;
                SQL);
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX time_entries_one_active_timer_per_user_unique ON time_entries (user_id) WHERE ended_at IS NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
