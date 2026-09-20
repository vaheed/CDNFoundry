<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'group' => 'display', 'values' => json_encode(['timezone' => 'UTC'], JSON_THROW_ON_ERROR),
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain the operator's display preference across an image rollback.
    }
};
