<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Expand step: a one-line description of what a configuration revision changed. Old releases ignore the column. */
    public function up(): void
    {
        Schema::table('edge_revisions', function (Blueprint $table): void {
            $table->string('change_summary', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edge_revisions', function (Blueprint $table): void {
            $table->dropColumn('change_summary');
        });
    }
};
