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
        Schema::table('visits', function (Blueprint $table) {
            $table->string('visit_type')->default('initial')->after('work_order_id');
            $table->foreignId('initial_visit_id')->nullable()->after('visit_type')->constrained('visits')->nullOnDelete();
            $table->index(['work_order_id', 'visit_type']);
        });

        $workOrderIds = DB::table('visits')->distinct()->pluck('work_order_id');

        foreach ($workOrderIds as $workOrderId) {
            $visits = DB::table('visits')
                ->where('work_order_id', $workOrderId)
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->pluck('id');
            $initialVisitId = $visits->first();

            if ($initialVisitId === null) {
                continue;
            }

            DB::table('visits')
                ->whereIn('id', $visits->all())
                ->update([
                    'visit_type' => 'revisit',
                    'initial_visit_id' => $initialVisitId,
                ]);
            DB::table('visits')
                ->where('id', $initialVisitId)
                ->update([
                    'visit_type' => 'initial',
                    'initial_visit_id' => null,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign(['initial_visit_id']);
            $table->dropIndex(['work_order_id', 'visit_type']);
            $table->dropColumn(['initial_visit_id', 'visit_type']);
        });
    }
};
