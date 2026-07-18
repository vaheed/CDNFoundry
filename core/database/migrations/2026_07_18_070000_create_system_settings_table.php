<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('group', 80)->primary();
            $table->jsonb('values');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE system_settings ADD CONSTRAINT system_settings_values_object CHECK (jsonb_typeof(values) = 'object')");
        }

        $now = now();
        $rows = collect(config('platform.groups'))->map(function (array $definition, string $group) use ($now): array {
            $defaults = collect($definition['fields'])->mapWithKeys(fn (array $field, string $key): array => [$key => $field['default']])->all();

            return ['group' => $group, 'values' => json_encode($defaults, JSON_THROW_ON_ERROR), 'revision' => 1, 'created_at' => $now, 'updated_at' => $now];
        })->values()->all();
        DB::table('system_settings')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
