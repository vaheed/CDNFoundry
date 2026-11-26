<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named lists of addresses and networks that a domain's firewall rules can test a client against. Entries are stored as
     * canonical CIDR strings in one bounded JSONB array, and the control plane compiles them into sorted ranges for the cells.
     */
    public function up(): void
    {
        Schema::create('ip_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('description', 250)->nullable();
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('entries');
            $table->unsignedInteger('entry_count')->default(0);
            $table->timestampsTz();
            $table->unique(['domain_id', 'name']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ip_lists ADD CONSTRAINT ip_lists_entries_bounded CHECK (jsonb_typeof(entries) = 'array' AND jsonb_array_length(entries) <= 10000 AND entry_count = jsonb_array_length(entries))");
            DB::statement("ALTER TABLE ip_lists ADD CONSTRAINT ip_lists_name_format CHECK (name ~ '^[a-z0-9][a-z0-9_-]{0,39}$')");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('IP lists cannot be removed once they exist.');
    }
};
