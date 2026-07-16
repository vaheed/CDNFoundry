<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 253);
            $table->string('display_name', 253);
            $table->string('lifecycle_state', 32)->default('pending_verification');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('nameservers_verified_at')->nullable();
            $table->foreignId('nameservers_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('deprovision_after')->nullable();
            $table->timestampsTz();
            $table->unique('name');
            $table->index(['lifecycle_state', 'id']);
        });

        Schema::create('domain_user', function (Blueprint $table): void {
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['domain_id', 'user_id']);
            $table->index(['user_id', 'domain_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_lifecycle_state_check CHECK (lifecycle_state IN ('pending_verification', 'active', 'disabled', 'deprovisioning'))");
            DB::statement('ALTER TABLE domains ADD CONSTRAINT domains_revision_positive CHECK (revision > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_user');
        Schema::dropIfExists('domains');
    }
};
