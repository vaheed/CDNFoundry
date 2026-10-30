<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_deployments', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('deployed_at');
            $table->index(['verified_at', 'id'], 'dns_deployments_verification_order');
        });
    }

    public function down(): void
    {
        Schema::table('dns_deployments', function (Blueprint $table): void {
            $table->dropIndex('dns_deployments_verification_order');
            $table->dropColumn('verified_at');
        });
    }
};
