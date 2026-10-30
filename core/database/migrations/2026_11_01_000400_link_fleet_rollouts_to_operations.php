<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_rollouts', function (Blueprint $table): void {
            $table->foreignUuid('operation_id')->nullable()->constrained('operations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_rollouts', fn (Blueprint $table) => $table->dropConstrainedForeignId('operation_id'));
    }
};
