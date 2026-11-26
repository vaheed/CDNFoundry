<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a domain's JWT validation settings (public keys, paths, issuer, audience). Null means validation is off. */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->{DB::getDriverName() === 'pgsql' ? 'jsonb' : 'json'}('jwt_validation')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_jwt_validation_object CHECK (jwt_validation IS NULL OR (jsonb_typeof(jwt_validation) = 'object' AND jsonb_typeof(jwt_validation->'keys') = 'array' AND jsonb_array_length(jwt_validation->'keys') BETWEEN 1 AND 8))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Expand-only migration: the jwt_validation column stays until a contract release removes it.');
    }
};
