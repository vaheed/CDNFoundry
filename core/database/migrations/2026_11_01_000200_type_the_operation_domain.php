<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->foreignId('domain_id')->nullable()->after('actor_id')->constrained('domains')->nullOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE operations o SET domain_id = d.id FROM domains d WHERE o.input ->> 'domain_id' ~ '^[0-9]{1,18}$' AND d.id = (o.input ->> 'domain_id')::bigint");
        }
        // Older duplicates would block the index; the newest unfinished operation per domain wins.
        DB::statement("UPDATE operations SET status = 'failed', error = 'Superseded by a coalesced operation.', finished_at = CURRENT_TIMESTAMP
            WHERE status IN ('pending', 'running') AND domain_id IS NOT NULL AND type IN ('dns.zone_reconcile', 'edge.domain_reconcile')
            AND EXISTS (SELECT 1 FROM operations newer WHERE newer.type = operations.type AND newer.domain_id = operations.domain_id
                AND newer.status IN ('pending', 'running') AND newer.created_at > operations.created_at)");
        DB::statement("CREATE UNIQUE INDEX operations_one_unfinished_per_domain ON operations (type, domain_id)
            WHERE status IN ('pending', 'running') AND domain_id IS NOT NULL AND type IN ('dns.zone_reconcile', 'edge.domain_reconcile')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS operations_one_unfinished_per_domain');
        Schema::table('operations', fn (Blueprint $table) => $table->dropConstrainedForeignId('domain_id'));
    }
};
