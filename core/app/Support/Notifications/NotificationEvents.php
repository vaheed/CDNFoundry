<?php

namespace App\Support\Notifications;

/** The notification events a channel can subscribe to. Domain events carry a domain; platform events never reach domain users. */
final class NotificationEvents
{
    public const PLATFORM = ['alert.firing' => 'Platform alert'];

    public const DOMAIN = [
        'certificate.issued' => 'Certificate issued',
        'certificate.failed' => 'Certificate issuance failed',
        'certificate.expiring' => 'Certificate expiring',
        'domain.verified' => 'Domain verified',
        'dns.deployment_failed' => 'DNS deployment failed',
        'security.state_changed' => 'Security state changed',
        'origin.errors_high' => 'Origin errors above threshold',
    ];

    /** @return array<string, string> */
    public static function forDomainUsers(): array
    {
        return self::DOMAIN;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::PLATFORM + self::DOMAIN;
    }

    public static function isDomainEvent(string $event): bool
    {
        return array_key_exists($event, self::DOMAIN);
    }
}
