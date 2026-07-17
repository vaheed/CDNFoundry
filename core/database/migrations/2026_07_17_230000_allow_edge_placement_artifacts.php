<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_artifacts', function (Blueprint $table): void {
            $table->dropUnique(['edge_id', 'domain_id', 'revision', 'kind']);
            $table->unique(['edge_id', 'domain_id', 'revision', 'kind', 'checksum'], 'edge_artifacts_content_unique');
        });
    }

    public function down(): void
    {
        Schema::table('edge_artifacts', function (Blueprint $table): void {
            $table->dropUnique('edge_artifacts_content_unique');
        });
    }
};
