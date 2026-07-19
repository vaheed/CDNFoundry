<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->json('cache_settings')->nullable();
            $table->unsignedBigInteger('cache_epoch')->default(1);
            $table->timestampTz('cache_development_mode_until')->nullable();
        });
        Schema::create('cache_purges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10);
            $table->unsignedBigInteger('cache_epoch');
            $table->json('cache_keys')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();
            $table->index(['domain_id', 'created_at']);
        });
        Schema::table('edge_tasks', function (Blueprint $table): void {
            $table->foreignUuid('cache_purge_id')->nullable()->constrained('cache_purges')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->unique(['edge_id', 'cache_purge_id']);
            $table->index(['status', 'available_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cache_purges ADD CONSTRAINT cache_purges_type_check CHECK (type IN ('all', 'urls'))");
            DB::statement("ALTER TABLE cache_purges ADD CONSTRAINT cache_purges_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'failed'))");
            DB::statement('ALTER TABLE cache_purges ADD CONSTRAINT cache_purges_payload_check CHECK ((type = \'all\' AND cache_keys IS NULL) OR (type = \'urls\' AND jsonb_typeof(cache_keys::jsonb) = \'array\'))');
        }
    }

    public function down(): void
    {
        Schema::table('edge_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cache_purge_id');
            $table->dropColumn(['attempts', 'available_at', 'last_error']);
        });
        Schema::dropIfExists('cache_purges');
        Schema::table('domains', fn (Blueprint $table) => $table->dropColumn(['cache_settings', 'cache_epoch', 'cache_development_mode_until']));
    }
};
