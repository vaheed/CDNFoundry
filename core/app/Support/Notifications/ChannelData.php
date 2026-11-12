<?php

namespace App\Support\Notifications;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Validates a channel definition once for the API and the panels, and builds the encrypted settings and the masked hint. */
final class ChannelData
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, name: string, secrets: array<string, string>, hint: string, events: ?list<string>, signing_secret: ?string}
     */
    public static function validate(array $input, ?User $owner): array
    {
        $platform = $owner === null;
        $types = $platform ? NotificationChannel::TYPES : ['telegram', 'discord', 'slack'];
        $events = $platform ? array_keys(NotificationEvents::all()) : array_keys(NotificationEvents::forDomainUsers());
        $validated = Validator::make($input, [
            'type' => ['required', Rule::in($types)],
            'name' => ['required', 'string', 'max:100'],
            'events' => [$platform ? 'nullable' : 'required', 'array', 'min:1', 'max:'.count($events)],
            'events.*' => ['string', 'distinct', Rule::in($events)],
            'bot_token' => ['required_if:type,telegram', 'nullable', 'string', 'regex:/^\d{5,12}:[A-Za-z0-9_-]{30,50}$/'],
            'chat_id' => ['required_if:type,telegram', 'nullable', 'string', 'regex:/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/'],
            'webhook_url' => ['required_if:type,discord,slack,webhook', 'nullable', 'string', 'max:2048'],
        ])->validate();

        $signing = null;
        try {
            [$secrets, $hint] = match ($validated['type']) {
                'telegram' => [['bot_token' => $validated['bot_token'], 'chat_id' => $validated['chat_id']], 'Telegram chat '.$validated['chat_id']],
                'discord', 'slack' => self::provider($validated['type'], $validated['webhook_url']),
                'webhook' => self::webhook($validated['webhook_url'], $signing),
            };
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['webhook_url' => $exception->getMessage()]);
        }

        return [
            'type' => $validated['type'], 'name' => $validated['name'], 'secrets' => $secrets, 'hint' => $hint,
            'events' => $validated['events'] ?? null, 'signing_secret' => $signing,
        ];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function provider(string $type, string $url): array
    {
        [$host] = OutboundDestination::providerUrl($type, $url);

        return [['webhook_url' => $url], ucfirst($type)." webhook on {$host}, ending ".substr($url, -4)];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function webhook(string $url, ?string &$signing): array
    {
        OutboundDestination::webhookUrl($url);
        $signing = Str::random(48);
        $parts = parse_url($url);

        return [['url' => $url, 'signing_secret' => $signing], 'Webhook to '.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }

    /** Whether a new channel fits the platform bound, checked inside the creating transaction. */
    public static function assertRoom(?User $owner): void
    {
        $settings = app(PlatformSettings::class);
        $count = NotificationChannel::query()->where('user_id', $owner?->getKey())->count();
        $limit = $owner === null ? $settings->integer('notifications', 'max_platform_channels') : $settings->integer('notifications', 'max_channels_per_user');
        if ($count >= $limit) {
            throw ValidationException::withMessages(['name' => "At most {$limit} notification channels are allowed. Remove one before adding another."]);
        }
    }
}
