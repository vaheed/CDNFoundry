<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand step: the state the platform sets from edge reports lives apart from the operator's configured state, so an
     * automatic change is an operational overlay with its own sequence and never a configuration revision. Only
     * `suspected` is set by automation alone, so it is the one value that moves; operator actions keep their own state.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->string('automatic_security_state', 20)->default('normal');
            $table->unsignedBigInteger('security_overlay_sequence')->default(0);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_automatic_security_state_check CHECK (automatic_security_state IN ('normal', 'suspected', 'restricted', 'recovering'))");
        }
        DB::table('domains')->where('security_state', 'suspected')->update([
            'automatic_security_state' => 'suspected',
            'security_state' => 'normal',
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_automatic_security_state_check');
        }
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['automatic_security_state', 'security_overlay_sequence']);
        });
    }
};
