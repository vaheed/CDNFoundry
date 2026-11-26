<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform's challenge keys. Tokens and seeds signed with an active key are accepted by every cell that holds the key,
     * so a token works on any cell serving the domain. At most one key is active; a rotated key stays valid for verification
     * until its retirement time, then is retired. `revision` rises with every change and is the version cells compare.
     */
    public function up(): void
    {
        Schema::create('challenge_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key_id', 16)->unique();
            $table->text('secret_ciphertext');
            $table->string('state', 10);
            $table->unsignedBigInteger('revision')->unique();
            $table->timestampTz('activated_at');
            $table->timestampTz('retire_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->string('retired_reason', 20)->nullable();
            $table->timestampsTz();
            $table->index('state');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE challenge_keys ADD CONSTRAINT challenge_keys_state_valid CHECK (state IN ('active', 'previous', 'retired'))");
            DB::statement("ALTER TABLE challenge_keys ADD CONSTRAINT challenge_keys_id_format CHECK (key_id ~ '^[0-9a-f]{16}$')");
            DB::statement("CREATE UNIQUE INDEX challenge_keys_one_active ON challenge_keys (state) WHERE state = 'active'");
            DB::statement("CREATE UNIQUE INDEX challenge_keys_one_previous ON challenge_keys (state) WHERE state = 'previous'");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Challenge keys cannot be removed once they exist.');
    }
};
