<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->string('name', 253);
            $table->text('content');
            $table->char('content_hash', 64);
            $table->unsignedInteger('ttl');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedSmallInteger('weight')->default(0);
            $table->unsignedSmallInteger('port')->default(0);
            $table->string('mode', 16)->default('dns_only');
            $table->timestampsTz();
            $table->unique(['domain_id', 'name', 'type', 'content_hash', 'priority', 'weight', 'port'], 'dns_records_logical_unique');
            $table->index(['domain_id', 'name', 'type', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_type_check CHECK (type IN ('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA', 'SRV', 'PTR'))");
            DB::statement("ALTER TABLE dns_records ADD CONSTRAINT dns_records_mode_check CHECK (mode = 'dns_only')");
            DB::statement('ALTER TABLE dns_records ADD CONSTRAINT dns_records_ttl_check CHECK (ttl BETWEEN 30 AND 2147483647)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
