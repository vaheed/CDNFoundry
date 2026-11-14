<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The five rule actions need a longer column, and the old allow/block check would refuse the new ones. */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE security_rules DROP CONSTRAINT IF EXISTS security_rules_action_check');
        DB::statement('ALTER TABLE security_rules ALTER COLUMN action TYPE varchar(16)');
    }

    public function down(): void
    {
        throw new RuntimeException('The widened action column cannot be narrowed again.');
    }
};
