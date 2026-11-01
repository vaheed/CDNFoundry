<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether an IPv6 nameserver address was seen answering for the platform zone, from an edge
        // outside the control plane. Saving a nameserver IPv6 address waits for an `answering` row.
        Schema::create('nameserver_address_probes', function (Blueprint $table): void {
            $table->id();
            $table->string('address', 45)->unique();
            $table->string('zone', 253);
            $table->string('status', 12)->default('pending');
            $table->timestampTz('requested_at');
            $table->timestampTz('answered_at')->nullable();
            $table->uuid('observer_edge_id')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->timestampsTz();
            $table->foreign('observer_edge_id')->references('id')->on('edges')->nullOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE nameserver_address_probes ADD CONSTRAINT nameserver_address_probes_status_check CHECK (status IN ('pending', 'answering', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nameserver_address_probes');
    }
};
