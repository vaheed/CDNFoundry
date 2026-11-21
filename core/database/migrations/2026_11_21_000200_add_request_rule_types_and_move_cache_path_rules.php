<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rewrite, header, and cache rules join redirects. Each domain's cache path rules become cache rules with a "starts with"
     * or "ends with" path condition, in the same order, and leave the cache settings: one engine decides per-path caching.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE request_rules DROP CONSTRAINT IF EXISTS request_rules_type_check');
            DB::statement("ALTER TABLE request_rules ADD CONSTRAINT request_rules_type_check CHECK (type IN ('redirect', 'rewrite', 'header', 'cache'))");
        }
        DB::table('domains')->whereNotNull('cache_settings')->orderBy('id')->select(['id', 'cache_settings'])->chunkById(200, function ($domains): void {
            foreach ($domains as $domain) {
                $settings = json_decode((string) $domain->cache_settings, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($settings) || ! array_key_exists('path_rules', $settings)) {
                    continue;
                }
                $now = now();
                $seen = [];
                foreach (array_values(is_array($settings['path_rules']) ? $settings['path_rules'] : []) as $index => $rule) {
                    $operator = ($rule['match'] ?? 'prefix') === 'suffix' ? 'ends_with' : 'starts_with';
                    $conditions = [['field' => 'path', 'operator' => $operator, 'value' => (string) ($rule['value'] ?? '/')]];
                    $action = ($rule['action'] ?? 'bypass') === 'ttl'
                        ? ['mode' => 'override', 'edge_ttl_seconds' => (int) ($rule['ttl_seconds'] ?? 0)]
                        : ['mode' => 'bypass'];
                    $fingerprint = hash('sha256', json_encode(['cache', $conditions, $action], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    if (isset($seen[$fingerprint])) {
                        continue;
                    }
                    $seen[$fingerprint] = true;
                    DB::table('request_rules')->insert([
                        'domain_id' => $domain->id, 'type' => 'cache', 'name' => null, 'enabled' => true, 'priority' => ($index + 1) * 10,
                        'conditions' => json_encode($conditions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        'action' => json_encode($action, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        'preset' => null, 'fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                unset($settings['path_rules']);
                DB::table('domains')->where('id', $domain->id)->update(['cache_settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'revision' => DB::raw('revision + 1')]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('request_rules')->where('type', '!=', 'redirect')->exists()) {
            throw new RuntimeException('Rewrite, header, or cache rules exist; rolling back would lose them.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE request_rules DROP CONSTRAINT IF EXISTS request_rules_type_check');
            DB::statement("ALTER TABLE request_rules ADD CONSTRAINT request_rules_type_check CHECK (type IN ('redirect'))");
        }
    }
};
