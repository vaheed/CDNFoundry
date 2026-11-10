<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: an operator can acknowledge a failed operation so it stops raising health alarms. Old releases ignore the columns. */
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->timestampTz('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE operations ADD CONSTRAINT operations_acknowledged_by_foreign FOREIGN KEY (acknowledged_by) REFERENCES users (id) ON DELETE SET NULL');
            DB::statement("CREATE INDEX operations_unresolved_failures_index ON operations (type, finished_at) WHERE status = 'failed' AND acknowledged_at IS NULL");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS operations_unresolved_failures_index');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE operations DROP CONSTRAINT IF EXISTS operations_acknowledged_by_foreign');
        }
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropColumn(['acknowledged_at', 'acknowledged_by']);
        });
    }
};
