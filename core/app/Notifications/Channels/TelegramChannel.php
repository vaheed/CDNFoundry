<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Notifications\PlatformEvent;

class TelegramChannel extends ProviderChannel
{
    protected function deliver(NotificationChannel $channel, PlatformEvent $event): void
    {
        $secrets = $channel->secrets();
        $base = $this->testBase() ?? 'https://api.telegram.org';
        $response = $this->client()->asJson()->post("{$base}/bot{$secrets['bot_token']}/sendMessage", [
            'chat_id' => $secrets['chat_id'], 'text' => $event->text(), 'disable_web_page_preview' => true,
        ]);
        $this->ensure($response, 'Telegram');
    }
}
