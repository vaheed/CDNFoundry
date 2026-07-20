<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('snapshot_id', 128)->nullable()->unique();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('manifest_sha256', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'deleting'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
