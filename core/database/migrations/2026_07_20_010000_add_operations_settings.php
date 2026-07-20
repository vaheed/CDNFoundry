<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definition = config('platform.groups.operations');
        $values = collect($definition['fields'])->mapWithKeys(fn (array $field, string $key): array => [$key => $field['default']])->all();
        DB::table('system_settings')->insertOrIgnore(['group' => 'operations', 'values' => json_encode($values, JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('group', 'operations')->delete();
    }
};
