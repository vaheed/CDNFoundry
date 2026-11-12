<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Notifications\PlatformEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Shared request settings for every provider: short timeouts, no redirects, and a bounded error message. */
abstract class ProviderChannel
{
    abstract protected function deliver(NotificationChannel $channel, PlatformEvent $event): void;

    public function send(NotificationChannel $notifiable, Notification $notification): void
    {
        $this->deliver($notifiable, $notification);
    }

    protected function client(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->acceptJson()->withUserAgent('CDNFoundry-Notifier');
    }

    protected function ensure(Response $response, string $provider): void
    {
        if (! $response->successful()) {
            throw new RuntimeException($provider.' answered HTTP '.$response->status().'.');
        }
    }

    /** A test or development provider address that replaces the real host. It is never honored in production. */
    protected function testBase(): ?string
    {
        $base = config('services.notifier.test_provider_url');

        return is_string($base) && $base !== '' && ! app()->environment('production') ? rtrim($base, '/') : null;
    }
}
