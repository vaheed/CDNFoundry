<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cache_purges', function (Blueprint $table): void {
            // A monotonic number, unique across domains: cells store it as the URL generation
            // and the edge agent uses it to replay purges after a cell restart.
            $table->unsignedBigInteger('sequence')->nullable()->unique();
            $table->foreignUuid('operation_id')->nullable()->constrained('operations')->nullOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS cache_purge_sequence');
            DB::statement('UPDATE cache_purges SET sequence = numbered.number FROM (SELECT id, nextval(\'cache_purge_sequence\') AS number FROM (SELECT id FROM cache_purges ORDER BY created_at, id) ordered) numbered WHERE cache_purges.id = numbered.id');
        } else {
            DB::statement('UPDATE cache_purges SET sequence = rowid');
        }
    }

    public function down(): void
    {
        Schema::table('cache_purges', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operation_id');
            $table->dropColumn('sequence');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS cache_purge_sequence');
        }
    }
};
