<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDnsSetting extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected static function booted(): void
    {
        // The stored pool defaults to the platform nameservers taken two at a time; it is data, not a runtime fallback.
        static::creating(function (self $settings): void {
            if ($settings->customer_nameserver_pairs === null) {
                $settings->customer_nameserver_pairs = array_values(array_filter(array_chunk($settings->nameservers ?? [], 2), fn (array $pair): bool => count($pair) === 2));
            }
        });
    }

    protected function casts(): array
    {
        return ['nameservers' => 'array', 'customer_nameserver_pairs' => 'array', 'revision' => 'integer'];
    }
}
