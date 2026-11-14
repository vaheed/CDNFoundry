<?php

namespace App\Support\LogPush;

/**
 * The only fields a destination can ever receive, per dataset. Each dataset reads one table for one domain. Edge and cell names,
 * internal addresses, node proofs, platform hostnames, other domains' ids, origin error text, and raw query strings are not here:
 * a request carries only its `query_hash`, as stored.
 */
final class LogDatasets
{
    public const HTTP_REQUESTS = 'http_requests';

    public const SECURITY_EVENTS = 'security_events';

    public const DNS_QUERIES = 'dns_queries';

    public const ALL = [self::HTTP_REQUESTS => 'HTTP requests', self::SECURITY_EVENTS => 'Security events', self::DNS_QUERIES => 'DNS queries'];

    private const REQUEST_FIELDS = ['request_id', 'hostname', 'method', 'path', 'query_hash', 'status', 'bytes_in', 'bytes_sent', 'cache_status', 'request_time_ms',
        'client_ip', 'country', 'continent', 'asn', 'user_agent', 'referrer', 'scheme', 'server_protocol', 'ssl_protocol', 'ip_version', 'compression_encoding'];

    private const SECURITY_FIELDS = ['request_id', 'hostname', 'method', 'path', 'status', 'client_ip', 'country', 'continent', 'asn', 'user_agent',
        'security_action', 'security_reason', 'waf_profile', 'waf_action', 'waf_rule_id', 'waf_score'];

    private const DNS_FIELDS = ['zone', 'qname', 'qtype', 'rcode', 'client_ip', 'country', 'continent', 'outcome', 'asn'];

    /** @return list<string> the fields a row of this dataset carries, after `occurred_at` */
    public static function fields(string $dataset): array
    {
        return match ($dataset) {
            self::HTTP_REQUESTS => self::REQUEST_FIELDS,
            self::SECURITY_EVENTS => self::SECURITY_FIELDS,
            self::DNS_QUERIES => self::DNS_FIELDS,
        };
    }

    public static function table(string $dataset): string
    {
        return $dataset === self::DNS_QUERIES ? 'cdnf.dns_events' : 'cdnf.edge_events';
    }

    /** The extra condition that selects the dataset's rows inside its table. */
    public static function condition(string $dataset): string
    {
        return match ($dataset) {
            self::HTTP_REQUESTS => "event_type != 'tls'",
            self::SECURITY_EVENTS => "event_type != 'tls' AND security_action != ''",
            self::DNS_QUERIES => '1',
        };
    }

    /** Truncates a client address to its /24 (IPv4) or /48 (IPv6) network. */
    public static function truncateAddress(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return '';
        }
        $keep = strlen($packed) === 4 ? 3 : 6;

        return (string) inet_ntop(substr($packed, 0, $keep).str_repeat("\0", strlen($packed) - $keep));
    }
}
