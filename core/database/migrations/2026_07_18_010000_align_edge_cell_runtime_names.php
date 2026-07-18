<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('edge_cells')->orderBy('id')->get()->each(function ($cell): void {
            $poolName = DB::table('edge_pools')->where('id', $cell->edge_pool_id)->value('name');
            if ($poolName !== null) {
                DB::table('edge_cells')->where('id', $cell->id)->update(['name' => $poolName]);
            }
        });
    }

    public function down(): void
    {
        DB::table('edge_cells')->orderBy('id')->get()->each(fn ($cell) => DB::table('edge_cells')->where('id', $cell->id)->update(['name' => 'pool-'.$cell->edge_pool_id]));
    }
};
