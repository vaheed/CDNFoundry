<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Expand step: the public header namespace. Cells without the field in their snapshot keep sending no public header. */
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'group' => 'public_headers', 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            'values' => json_encode(['enabled' => true, 'prefix' => 'cdnf-', 'pop_label' => '']),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'public_headers')->delete();
    }
};
