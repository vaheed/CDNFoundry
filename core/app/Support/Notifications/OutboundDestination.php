<?php

namespace App\Support\Notifications;

use App\Support\NetworkAddress;
use App\Support\OriginPolicy;
use App\Support\PlatformSettings;
use RuntimeException;

/**
 * Destination checks for notification egress. Provider URLs must be HTTPS on the provider's own host. The generic
 * webhook gets the origin checks: no loopback, link-local, metadata, multicast, or platform address, and the resolved
 * address is checked again and pinned at connection time.
 */
final class OutboundDestination
{
    public const PROVIDER_HOSTS = [
        'discord' => ['discord.com', 'discordapp.com'],
        'slack' => ['hooks.slack.com'],
    ];

    /** @return array{0: string, 1: int} the host and port of a valid HTTPS provider URL, or a message through the exception */
    public static function providerUrl(string $type, string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('The URL must be an https:// address without credentials.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, self::PROVIDER_HOSTS[$type] ?? [], true) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('The URL must use the provider\'s own host: '.implode(' or ', self::PROVIDER_HOSTS[$type] ?? []).'.');
        }
        $path = (string) ($parts['path'] ?? '');
        $valid = $type === 'discord' ? preg_match('~^/api/webhooks/\d{5,25}/[A-Za-z0-9_-]{20,100}$~', $path) : preg_match('~^/services/T[A-Z0-9]+/B[A-Z0-9]+/[A-Za-z0-9]{16,60}$~', $path);
        if (! $valid || isset($parts['query'])) {
            throw new RuntimeException('The URL is not a '.ucfirst($type).' webhook address.');
        }

        return [$host, 443];
    }

    /** Validates a generic webhook URL at save time. Names are resolved again at delivery. */
    public static function webhookUrl(string $url): void
    {
        self::webhookTarget($url);
    }

    /**
     * Resolves the webhook host and refuses every unsafe address.
     *
     * @return array{host: string, port: int, address: string, url: string}
     */
    public static function webhookTarget(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($parts === false || ! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            throw new RuntimeException('The webhook URL must be an http:// or https:// address without credentials.');
        }
        $host = trim(strtolower($parts['host']), '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($addresses === []) {
            throw new RuntimeException('The webhook host does not resolve.');
        }
        foreach ($addresses as $address) {
            if (self::refused($address)) {
                throw new RuntimeException('The webhook host resolves to a loopback, private, link-local, metadata, or platform address.');
            }
        }

        return ['host' => $host, 'port' => $port, 'address' => $addresses[0], 'url' => $url];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    private static function refused(string $address): bool
    {
        $settings = app(PlatformSettings::class);
        $policy = OriginPolicy::compile();
        if (in_array($address, $policy['blocked_addresses'], true)
            || collect($policy['blocked_networks'])->contains(fn (string $cidr): bool => NetworkAddress::inCidr($address, $cidr))) {
            return true;
        }
        if (! NetworkAddress::isUnsafe($address)) {
            return false;
        }

        // Only a narrow private allow-list can admit an ordinary private address; loopback and the like never pass.
        return ! (NetworkAddress::isPrivate($address)
            && collect($settings->get('origin_safety', 'private_origin_allowlist'))->contains(fn (string $cidr): bool => NetworkAddress::inCidr($address, $cidr))
            && ! NetworkAddress::inCidr($address, '127.0.0.0/8') && ! NetworkAddress::inCidr($address, '169.254.0.0/16'));
    }
}
