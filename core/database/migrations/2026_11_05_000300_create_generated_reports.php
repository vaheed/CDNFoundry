<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->string('period', 12);
            $table->string('format', 4);
            $table->string('path', 160);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['kind', 'period', 'format']);
            $table->index(['created_at', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE generated_reports ADD CONSTRAINT generated_reports_kind_check CHECK (kind IN ('usage', 'operations'))");
            DB::statement("ALTER TABLE generated_reports ADD CONSTRAINT generated_reports_format_check CHECK (format IN ('csv', 'pdf'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
    }
};
