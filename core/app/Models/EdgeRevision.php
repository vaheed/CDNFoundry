<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeRevision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sections of the snapshot a person can recognise, in the order they are listed. */
    private const SECTIONS = [
        'hostnames' => 'hostnames and origins', 'settings' => 'proxy settings', 'cache' => 'cache', 'compression' => 'compression',
        'security' => 'security', 'waf' => 'WAF', 'tls' => 'TLS', 'pools' => 'pool placement',
    ];

    /** One line naming which parts of the configuration differ from the previous revision. */
    public static function describeChange(?array $previous, array $current): string
    {
        if ($previous === null) {
            return 'First configuration';
        }
        $changed = [];
        foreach (self::SECTIONS as $key => $label) {
            if (($previous[$key] ?? null) != ($current[$key] ?? null)) {
                $changed[] = $label;
            }
        }

        return $changed === [] ? 'No configuration change' : 'Changed '.implode(', ', $changed);
    }
}
