<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $scrub = function (mixed $value) use (&$scrub): mixed {
            if (! is_array($value)) {
                return $value;
            }
            unset($value['private_key_pem']);

            return array_map($scrub, $value);
        };
        foreach ([['edge_revisions', 'id', 'snapshot'], ['edge_artifacts', 'sequence', 'payload']] as [$table, $key, $column]) {
            DB::table($table)->where($column, 'like', '%private_key_pem%')->orderBy($key)->chunkById(100, function ($rows) use ($table, $key, $column, $scrub): void {
                foreach ($rows as $row) {
                    DB::table($table)->where($key, $row->{$key})->update([$column => json_encode($scrub(json_decode($row->{$column}, true)), JSON_UNESCAPED_SLASHES)]);
                }
            }, $key);
        }
    }

    public function down(): void
    {
        // Removed key material is intentionally not restorable.
    }
};
