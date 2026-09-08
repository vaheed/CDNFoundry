<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            // Existing verified zones retain their nameservers. Pending legacy
            // claims receive an assignment when verification is requested again.
            $table->string('delegation_token', 32)->nullable()->unique();
            $table->jsonb('delegation_nameservers')->nullable();
            $table->timestampTz('claim_expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Rolling application code back must retain these fields and DNS aliases.
        // Dropping claim evidence would make stale delegation reusable.
        throw new RuntimeException('Delegation claim evidence must be retained; use a forward recovery migration.');
    }
};
