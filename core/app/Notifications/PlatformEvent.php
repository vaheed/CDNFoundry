<?php

namespace App\Notifications;

use App\Models\NotificationChannel;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Notifications\Notification;

/**
 * One platform event, delivered by the channel class that matches the destination type. The message holds only the
 * event, a title, a body, and the domain name; a domain user's channel never receives another domain's event.
 */
class PlatformEvent extends Notification
{
    /** @param array{event: string, title: string, body: string, domain: ?string, occurred_at: string, count?: int} $message */
    public function __construct(public readonly array $message) {}

    /** @return list<class-string> */
    public function via(NotificationChannel $channel): array
    {
        return [match ($channel->type) {
            'telegram' => TelegramChannel::class,
            'discord' => DiscordChannel::class,
            'slack' => SlackChannel::class,
            'webhook' => WebhookChannel::class,
        }];
    }

    public function text(): string
    {
        $message = $this->message;
        $lines = ['['.config('app.name', 'CDNFoundry').'] '.$message['title']];
        if (($message['body'] ?? '') !== '') {
            $lines[] = $message['body'];
        }
        if (($message['domain'] ?? null) !== null) {
            $lines[] = 'Domain: '.$message['domain'];
        }

        return mb_substr(implode("\n", $lines), 0, 1800);
    }
}
