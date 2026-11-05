<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Version 2 agents accept artifacts for agents 2.0.0 to 2.99.99. The 1.x range is gone: 2.0 has no mixed 1.x fleet.
     */
    public function up(): void
    {
        Schema::table('edge_artifacts', function (Blueprint $table): void {
            $table->string('minimum_agent_version', 20)->default('2.0.0')->change();
            $table->string('maximum_agent_version', 20)->default('2.99.99')->change();
        });
    }

    public function down(): void
    {
        Schema::table('edge_artifacts', function (Blueprint $table): void {
            $table->string('minimum_agent_version', 20)->default('1.0.0')->change();
            $table->string('maximum_agent_version', 20)->default('1.99.99')->change();
        });
    }
};
