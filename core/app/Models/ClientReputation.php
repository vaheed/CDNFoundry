<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The shadow reputation of one client key across cells. Nothing reads it to decide a request.
 *
 * @property string $client_key
 * @property int $score
 * @property string $band
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface $last_seen_at
 * @property int $events
 * @property int $requests
 * @property int $domain_count
 * @property int $cell_count
 * @property int $edge_count
 */
class ClientReputation extends Model
{
    protected $table = 'client_reputation';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
