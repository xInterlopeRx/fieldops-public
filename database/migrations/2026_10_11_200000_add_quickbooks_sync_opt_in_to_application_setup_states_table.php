<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quickbooks_initial_customer_imports', function (Blueprint $table): void {
            $table->dropUnique('quickbooks_initial_customer_imports_environment_unique');
            $table->unique(
                ['environment', 'source'],
                'qbo_initial_import_environment_source_unique',
            );
        });

        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->boolean('quickbooks_sync_opted_in')->default(false);
            $table->timestampTz('quickbooks_sync_opted_in_at')->nullable();
            $table->boolean('quickbooks_sync_verification_required')->default(false);
        });

        DB::table('application_setup_states')
            ->where('onboarding_required', true)
            ->where(function ($query): void {
                $query
                    ->whereExists(fn ($subquery) => $subquery
                        ->selectRaw('1')
                        ->from('integration_connections')
                        ->where('provider', 'quickbooks'))
                    ->orWhereExists(fn ($subquery) => $subquery
                        ->selectRaw('1')
                        ->from('quickbooks_initial_customer_imports')
                        ->where('source', 'quickbooks_api'))
                    ->orWhereExists(fn ($subquery) => $subquery
                        ->selectRaw('1')
                        ->from('customers')
                        ->whereNotNull('quickbooks_id'))
                    ->orWhereExists(fn ($subquery) => $subquery
                        ->selectRaw('1')
                        ->from('sites')
                        ->whereNotNull('quickbooks_id'))
                    ->orWhereExists(fn ($subquery) => $subquery
                        ->selectRaw('1')
                        ->from('items')
                        ->whereNotNull('quickbooks_id'));
            })
            ->update([
                'quickbooks_sync_opted_in' => true,
                'quickbooks_sync_opted_in_at' => now(),
                'quickbooks_sync_verification_required' => true,
            ]);
    }

    public function down(): void
    {
        if (DB::table('application_setup_states')->where('quickbooks_sync_opted_in', true)->exists()) {
            throw new RuntimeException('Cannot remove the QuickBooks opt-in decision while an installation has opted in.');
        }

        $hasMultipleSourceRuns = DB::table('quickbooks_initial_customer_imports')
            ->select('environment')
            ->groupBy('environment')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleSourceRuns) {
            throw new RuntimeException('Cannot restore a single import cursor while multiple source runs exist for an environment.');
        }

        Schema::table('quickbooks_initial_customer_imports', function (Blueprint $table): void {
            $table->dropUnique('qbo_initial_import_environment_source_unique');
            $table->unique('environment');
        });

        Schema::table('application_setup_states', function (Blueprint $table): void {
            $table->dropColumn([
                'quickbooks_sync_opted_in',
                'quickbooks_sync_opted_in_at',
                'quickbooks_sync_verification_required',
            ]);
        });
    }
};
