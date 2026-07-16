<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('type', 16)->default('user')->after('password');
            $table->timestampTz('disabled_at')->nullable()->after('type');
            $table->index(['type', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (type IN ('admin', 'user'))");
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['type', 'id']);
            $table->dropColumn(['type', 'disabled_at']);
        });
    }
};
