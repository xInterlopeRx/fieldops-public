<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('source', 20)->default('manual');
            $table->string('middle_name')->nullable();
            $table->string('title')->nullable();
            $table->string('suffix')->nullable();
            $table->string('alternate_phone', 64)->nullable();
            $table->string('mobile_phone', 64)->nullable();
            $table->string('fax', 64)->nullable();
            $table->string('website', 1000)->nullable();
            $table->index(['site_id', 'contact_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'contact_type']);
            $table->dropForeign(['site_id']);
            $table->dropColumn([
                'site_id',
                'source',
                'middle_name',
                'title',
                'suffix',
                'alternate_phone',
                'mobile_phone',
                'fax',
                'website',
            ]);
        });
    }
};
