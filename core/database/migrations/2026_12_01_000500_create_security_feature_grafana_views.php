<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Read-only operational views for the Grafana role. They carry counts, states and times only: no challenge secret, no client key
     * and no rule value. PostgreSQL only; the isolated test database has no Grafana role and needs none of them.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE OR REPLACE VIEW grafana_security_rule_lifecycle AS SELECT id, domain_id, action, mode, enabled, expires_at, created_at FROM security_rules');
        DB::statement('CREATE OR REPLACE VIEW grafana_challenge_keys AS SELECT key_id, state, revision, activated_at, retire_at, retired_at, retired_reason FROM challenge_keys');
        DB::statement('CREATE OR REPLACE VIEW grafana_client_reputation_bands AS SELECT band, count(*) AS clients, max(last_seen_at) AS last_seen_at FROM client_reputation GROUP BY band');
        DB::statement('CREATE OR REPLACE VIEW grafana_client_reputation_reports AS SELECT count(*) AS reports, count(DISTINCT edge_id) AS reporting_edges, max(reported_at) AS last_reported_at FROM client_reputation_reports');
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: the operational views stay until a contract release removes them.');
    }
};
