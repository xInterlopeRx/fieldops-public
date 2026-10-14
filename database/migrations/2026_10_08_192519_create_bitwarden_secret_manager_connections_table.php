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
        Schema::create('bitwarden_secret_manager_connections', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20)->unique();
            $table->string('organization_id', 36);
            $table->string('project_id', 36);
            $table->string('api_url', 512);
            $table->string('identity_url', 512);
            $table->text('runtime_access_token');
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('configured_at')->nullable();
            $table->string('verification_status', 24)->default('unverified');
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bitwarden_secret_manager_connections');
    }
};
