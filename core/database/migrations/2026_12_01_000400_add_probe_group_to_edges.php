<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: an optional, administrator-set label for edges that leave through a different network from the rest of their
     * region. Origin checks use it to pick one representative from each reachability group. Nothing reads it until it is set.
     */
    public function up(): void
    {
        Schema::table('edges', function ($table): void {
            $table->string('probe_group', 40)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table edges add constraint edges_probe_group_check check (probe_group is null or probe_group ~ '^[a-z0-9][a-z0-9._-]*$')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table edges drop constraint if exists edges_probe_group_check');
        }
        Schema::table('edges', function ($table): void {
            $table->dropColumn('probe_group');
        });
    }
};
