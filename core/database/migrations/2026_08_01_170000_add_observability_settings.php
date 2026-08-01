<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'group' => 'observability',
            'values' => json_encode(['grafana_explore_url' => null], JSON_THROW_ON_ERROR),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'observability')->delete();
    }
};
