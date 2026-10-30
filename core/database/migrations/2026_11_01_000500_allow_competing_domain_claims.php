<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Several claims may share a name; only one active or disabled domain may hold it. A domain being
        // deprovisioned no longer holds the name, so a winning claim can activate while it retires.
        DB::statement('DROP INDEX IF EXISTS domains_name_active_unique');
        DB::statement("CREATE UNIQUE INDEX domains_name_holder_unique ON domains (name) WHERE deleted_at IS NULL AND lifecycle_state IN ('active', 'disabled')");
        DB::statement('CREATE INDEX domains_name_live ON domains (name) WHERE deleted_at IS NULL');

        // Delegation names were random hex labels behind a wildcard record. Claims now use the
        // platform's readable nameserver pairs, so earlier assignments are cleared and
        // reassigned on the next verification request.
        DB::table('domains')->update(['delegation_nameservers' => null]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS domains_name_live');
        DB::statement('DROP INDEX IF EXISTS domains_name_holder_unique');
        DB::statement('CREATE UNIQUE INDEX domains_name_active_unique ON domains (name) WHERE deleted_at IS NULL');
    }
};
