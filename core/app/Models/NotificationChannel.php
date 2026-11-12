<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;

/** A delivery destination for notifications. A null owner means a platform channel that receives every event. */
class NotificationChannel extends Model
{
    use HasUuids;
    use Notifiable;

    public const TYPES = ['telegram', 'discord', 'slack', 'webhook'];

    protected $guarded = [];

    protected $hidden = ['secret_ciphertext'];

    protected static function booted(): void
    {
        $forget = fn () => \App\Support\Notifications\EventPublisher::forgetPlatformChannels();
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'events' => 'array', 'consecutive_failures' => 'integer',
            'last_success_at' => 'immutable_datetime', 'last_failure_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPlatform(): bool
    {
        return $this->user_id === null;
    }

    /** @return array<string, string> the decrypted provider settings: tokens and URLs never leave the sender. */
    public function secrets(): array
    {
        return json_decode(Crypt::decryptString($this->secret_ciphertext), true, 8, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $secrets */
    public function storeSecrets(array $secrets): void
    {
        $this->secret_ciphertext = Crypt::encryptString(json_encode($secrets, JSON_THROW_ON_ERROR));
    }

    public function wantsEvent(string $event): bool
    {
        return $this->events === null || in_array($event, $this->events, true);
    }
}
