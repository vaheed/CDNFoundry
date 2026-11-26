<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared client reputation, shadow stage. A domain opts in with `reputation_sharing`. A cell reports the clients that caused the
     * most escalating events on enrolled domains, with how many requests it saw from them since; the control plane adds the reports of
     * the last five minutes, keeps a band per client that decays, and publishes nothing to any cell. Both tables are derived, bounded
     * and short-lived: reports end after an hour and a band row after seven days at most.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->boolean('reputation_sharing')->default(false);
        });
        Schema::create('client_reputation_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('edge_id')->constrained()->cascadeOnDelete();
            $table->string('cell_name', 16);
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('client_key', 64);
            $table->unsignedInteger('events');
            $table->unsignedBigInteger('requests');
            $table->timestampTz('reported_at');
            $table->unique(['edge_id', 'cell_name', 'domain_id', 'client_key'], 'client_reputation_reports_cell_client');
            $table->index('reported_at');
            $table->index('client_key');
        });
        Schema::create('client_reputation', function (Blueprint $table): void {
            $table->id();
            $table->string('client_key', 64)->unique();
            $table->unsignedInteger('score');
            $table->string('band', 10);
            $table->unsignedInteger('events');
            $table->unsignedBigInteger('requests');
            $table->unsignedSmallInteger('domain_count');
            $table->unsignedSmallInteger('cell_count');
            $table->unsignedSmallInteger('edge_count');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
            $table->index('score');
            $table->index('last_seen_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE client_reputation ADD CONSTRAINT client_reputation_band_known CHECK (band IN ('watch', 'elevated', 'high'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: the reputation tables and the opt-in column stay until a contract release removes them.');
    }
};
