<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DnsZoneChange extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deleted' => 'boolean'];
    }

    /** Record that a zone changed on the primary, giving it the next cursor value. */
    public static function record(string $zone, int $serial, bool $deleted = false): void
    {
        DB::transaction(function () use ($zone, $serial, $deleted): void {
            static::query()->where('zone', $zone)->delete();
            static::query()->create(['zone' => $zone, 'serial' => $serial, 'deleted' => $deleted, 'created_at' => now()]);
        });
    }
}
