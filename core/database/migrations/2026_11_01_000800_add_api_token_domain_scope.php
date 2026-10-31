<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A token with rows here may act only on these domains. A token with none is not domain-scoped.
        Schema::create('personal_access_token_domains', function (Blueprint $table): void {
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->primary(['personal_access_token_id', 'domain_id']);
            $table->index('domain_id');
        });

        // Tokens that exist before the expiry rule keep working for 90 days, then must be replaced.
        DB::table('personal_access_tokens')->whereNull('expires_at')->update(['expires_at' => now()->addDays(90)]);
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_token_domains');
    }
};
