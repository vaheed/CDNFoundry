<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing verified zones retain their nameservers. Pending legacy
        // claims receive an assignment when verification is requested again.
        if (! Schema::hasColumn('domains', 'delegation_token')) {
            Schema::table('domains', fn (Blueprint $table) => $table->string('delegation_token', 32)->nullable()->unique());
        }
        if (! Schema::hasColumn('domains', 'delegation_nameservers')) {
            Schema::table('domains', fn (Blueprint $table) => $table->jsonb('delegation_nameservers')->nullable());
        } elseif (DB::getDriverName() === 'pgsql') {
            $type = DB::table('information_schema.columns')->where('table_schema', 'public')
                ->where('table_name', 'domains')->where('column_name', 'delegation_nameservers')->value('data_type');
            if ($type === 'json') {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL statement_timeout = '30s'");
                DB::statement('ALTER TABLE domains ALTER COLUMN delegation_nameservers TYPE jsonb USING delegation_nameservers::jsonb');
            } elseif ($type !== 'jsonb') {
                throw new RuntimeException('The existing delegation nameservers column must be JSON or JSONB.');
            }
        }
        if (! Schema::hasColumn('domains', 'claim_expires_at')) {
            Schema::table('domains', fn (Blueprint $table) => $table->timestampTz('claim_expires_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        // Rolling application code back must retain these fields and DNS aliases.
        // Dropping claim evidence would make stale delegation reusable.
        throw new RuntimeException('Delegation claim evidence must be retained; use a forward recovery migration.');
    }
};
