<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Long-window (1 hour, 24 hour) rate limits of the `shared` scope are enforced by the cells' own counters and, for the part no
     * single cell sees, by the control plane. A cell reports the heaviest keys of such a rule (a hash, never the value) with its
     * count for the current window; the control plane adds the latest count of every cell and, when the total reaches the rule's
     * threshold, records a mitigation that goes back to every edge serving the domain. Both tables are derived and bounded.
     */
    public function up(): void
    {
        Schema::create('rate_window_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('edge_id')->constrained()->cascadeOnDelete();
            $table->string('cell_name', 16);
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rule_id');
            $table->string('key_hash', 32);
            $table->unsignedBigInteger('window');
            $table->unsignedBigInteger('count');
            $table->timestampTz('reported_at');
            $table->unique(['edge_id', 'cell_name', 'domain_id', 'rule_id', 'key_hash', 'window'], 'rate_window_reports_cell_key');
            $table->index(['domain_id', 'rule_id', 'window']);
            $table->index('reported_at');
        });
        Schema::create('rate_mitigations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rule_id');
            $table->string('key_hash', 32);
            $table->unsignedBigInteger('revision')->unique();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['domain_id', 'rule_id', 'key_hash']);
            $table->index('expires_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE rate_window_reports ADD CONSTRAINT rate_window_reports_hash_format CHECK (key_hash ~ '^[0-9a-f]{32}$')");
            DB::statement("ALTER TABLE rate_mitigations ADD CONSTRAINT rate_mitigations_hash_format CHECK (key_hash ~ '^[0-9a-f]{32}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_mitigations');
        Schema::dropIfExists('rate_window_reports');
    }
};
