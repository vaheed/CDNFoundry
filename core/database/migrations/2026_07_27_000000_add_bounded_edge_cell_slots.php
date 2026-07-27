<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_SLOTS = 8;

    public function up(): void
    {
        Schema::table('edges', function (Blueprint $table): void {
            $table->unsignedSmallInteger('cell_slot_count')->default(self::DEFAULT_SLOTS)->after('drained');
        });
        Schema::table('edge_cells', function (Blueprint $table): void {
            $table->dropUnique(['edge_id', 'edge_pool_id']);
            $table->unsignedSmallInteger('slot')->nullable()->after('edge_id');
            $table->unsignedSmallInteger('http_port')->nullable()->after('name');
            $table->unsignedSmallInteger('https_port')->nullable()->after('http_port');
            $table->unsignedSmallInteger('status_port')->nullable()->after('https_port');
            $table->string('runtime_path', 255)->nullable()->after('status_port');
            $table->string('cache_path', 255)->nullable()->after('runtime_path');
            $table->string('temporary_path', 255)->nullable()->after('cache_path');
            $table->json('resource_limits')->nullable()->after('temporary_path');
        });

        DB::table('edges')->orderBy('id')->get(['id', 'cell_slot_count'])->each(function ($edge): void {
            $existing = DB::table('edge_cells')->where('edge_id', $edge->id)->orderByRaw("CASE WHEN name = 'shared-default' THEN 0 WHEN name = 'quarantine-default' THEN 1 ELSE 2 END")->orderBy('id')->get();
            foreach ($existing as $offset => $cell) {
                $slot = $offset + 1;
                DB::table('edge_cells')->where('id', $cell->id)->update($this->slotAttributes($slot));
            }
            for ($slot = $existing->count() + 1; $slot <= (int) $edge->cell_slot_count; $slot++) {
                DB::table('edge_cells')->insert([
                    'edge_id' => $edge->id,
                    'edge_pool_id' => null,
                    'drained' => false,
                    'status' => 'stopped',
                    'capacity' => null,
                    'service_ipv4' => null,
                    'service_ipv6' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    ...$this->slotAttributes($slot),
                ]);
            }
        });

        Schema::table('edge_cells', function (Blueprint $table): void {
            $table->unsignedSmallInteger('slot')->nullable(false)->change();
            $table->foreignId('edge_pool_id')->nullable()->change();
            $table->unique(['edge_id', 'slot']);
            $table->unique(['edge_id', 'edge_pool_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE edge_cells DROP CONSTRAINT IF EXISTS edge_cells_status_check');
            DB::statement("ALTER TABLE edge_cells ADD CONSTRAINT edge_cells_status_check CHECK (status IN ('assigned', 'unassigned', 'ready', 'degraded', 'drained', 'stopped'))");
            DB::statement('ALTER TABLE edges ADD CONSTRAINT edges_cell_slot_count_check CHECK (cell_slot_count BETWEEN 1 AND 32)');
            DB::statement('ALTER TABLE edge_cells ADD CONSTRAINT edge_cells_slot_check CHECK (slot BETWEEN 1 AND 32)');
            DB::statement('ALTER TABLE edge_cells ADD CONSTRAINT edge_cells_ports_check CHECK (http_port BETWEEN 1 AND 65535 AND https_port BETWEEN 1 AND 65535 AND status_port BETWEEN 1 AND 65535 AND http_port <> https_port AND http_port <> status_port AND https_port <> status_port)');
            DB::statement("ALTER TABLE edge_cells ADD CONSTRAINT edge_cells_name_check CHECK (name = 'cell-' || lpad(slot::text, 2, '0'))");
            DB::statement("ALTER TABLE edge_cells ADD CONSTRAINT edge_cells_paths_check CHECK (runtime_path = '/var/lib/cdnfoundry/runtime/' || name || '.json' AND cache_path = '/var/cache/cdnfoundry/' || name AND temporary_path = '/var/lib/cdnfoundry/tmp/' || name)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['edge_cells_paths_check', 'edge_cells_name_check', 'edge_cells_ports_check', 'edge_cells_slot_check'] as $constraint) {
                DB::statement("ALTER TABLE edge_cells DROP CONSTRAINT IF EXISTS {$constraint}");
            }
            DB::statement('ALTER TABLE edges DROP CONSTRAINT IF EXISTS edges_cell_slot_count_check');
            DB::statement('ALTER TABLE edge_cells DROP CONSTRAINT IF EXISTS edge_cells_status_check');
            DB::statement("ALTER TABLE edge_cells ADD CONSTRAINT edge_cells_status_check CHECK (status IN ('pending', 'ready', 'degraded', 'failed', 'drained'))");
        }
        DB::table('edge_cells')->whereNull('edge_pool_id')->delete();
        Schema::table('edge_cells', function (Blueprint $table): void {
            $table->dropUnique(['edge_id', 'edge_pool_id']);
            $table->dropUnique(['edge_id', 'slot']);
            $table->foreignId('edge_pool_id')->nullable(false)->change();
            $table->dropColumn(['slot', 'http_port', 'https_port', 'status_port', 'runtime_path', 'cache_path', 'temporary_path', 'resource_limits']);
            $table->unique(['edge_id', 'edge_pool_id']);
        });
        Schema::table('edges', fn (Blueprint $table) => $table->dropColumn('cell_slot_count'));
    }

    private function slotAttributes(int $slot): array
    {
        $name = sprintf('cell-%02d', $slot);

        return [
            'slot' => $slot,
            'name' => $name,
            'http_port' => 18080 + $slot,
            'https_port' => 18443 + $slot,
            'status_port' => 19080 + $slot,
            'runtime_path' => "/var/lib/cdnfoundry/runtime/{$name}.json",
            'cache_path' => "/var/cache/cdnfoundry/{$name}",
            'temporary_path' => "/var/lib/cdnfoundry/tmp/{$name}",
            'resource_limits' => json_encode(['memory_bytes' => 536870912, 'cpu_millis' => 500, 'pids' => 128, 'cache_bytes' => 268435456, 'temporary_bytes' => 67108864, 'log_bytes' => 16777216], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];
    }
};
