<?php

namespace App\Notifications\Channels;

use App\Models\NotificationChannel;
use App\Notifications\PlatformEvent;
use App\Support\Notifications\OutboundDestination;

/** The optional administrator-only generic webhook: a JSON body signed with HMAC-SHA256 and a destination checked at connection time. */
class WebhookChannel extends ProviderChannel
{
    protected function deliver(NotificationChannel $channel, PlatformEvent $event): void
    {
        $secrets = $channel->secrets();
        // Resolve again now and connect only to the address that passed the check, so a changed DNS answer cannot redirect delivery.
        $target = OutboundDestination::webhookTarget($secrets['url']);
        $body = json_encode(['source' => 'cdnfoundry', ...$event->message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $request = $this->client()->withBody($body, 'application/json')->withHeaders([
            'X-CDNFoundry-Signature' => 'sha256='.hash_hmac('sha256', $body, $secrets['signing_secret']),
        ]);
        if (! filter_var($target['host'], FILTER_VALIDATE_IP)) {
            $address = str_contains($target['address'], ':') ? "[{$target['address']}]" : $target['address'];
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$address}"]]]);
        }
        $this->ensure($request->post($target['url']), 'The webhook');
    }
}
