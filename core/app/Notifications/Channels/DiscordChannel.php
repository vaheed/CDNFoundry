<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Notifications\PlatformEvent;
use App\Support\Notifications\OutboundDestination;

class DiscordChannel extends ProviderChannel
{
    protected function deliver(NotificationChannel $channel, PlatformEvent $event): void
    {
        $url = $channel->secrets()['webhook_url'];
        OutboundDestination::providerUrl('discord', $url);
        $target = $this->testBase() === null ? $url : $this->testBase().parse_url($url, PHP_URL_PATH);
        $response = $this->client()->asJson()->post($target, [
            'content' => mb_substr($event->text(), 0, 1900), 'allowed_mentions' => ['parse' => []],
        ]);
        $this->ensure($response, 'Discord');
    }
}
