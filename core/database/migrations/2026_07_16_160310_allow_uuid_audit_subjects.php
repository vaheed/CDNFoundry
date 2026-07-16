<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN subject_id TYPE varchar(255) USING subject_id::text');
        }
    }

    public function down(): void
    {
        // Mixed numeric and UUID audit subjects cannot be safely converted back.
    }
};
