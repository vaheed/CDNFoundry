<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['sensitive_data_check', 'ai_protection'];

    /** Expand step: the kinds of sensitive data to look for in responses, and a domain's LLM endpoints. Null means the detection is off. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}($column)->nullable();
            }
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::COLUMNS as $column) {
                DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_{$column}_object CHECK ($column IS NULL OR jsonb_typeof($column) = 'object')");
            }
        }
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                foreach (self::COLUMNS as $column) {
                    DB::statement("ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_{$column}_object");
                }
            }
            $table->dropColumn(self::COLUMNS);
        });
    }
};
