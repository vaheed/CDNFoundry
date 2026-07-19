<?php

return [
    'groups' => [
        'dns_lifecycle' => [
            'label' => 'DNS lifecycle',
            'description' => 'Retention windows for disabled domains and released domain names.',
            'fields' => [
                'deprovision_delay_days' => [
                    'type' => 'integer', 'label' => 'Deprovision delay (days)', 'default' => 7,
                    'description' => 'Days a disabled domain continues serving its last valid DNS and edge state before asynchronous removal begins.',
                    'rules' => ['required', 'integer', 'between:1,365'],
                ],
                'domain_reclaim_cooldown_days' => [
                    'type' => 'integer', 'label' => 'Domain reclaim cooldown (days)', 'default' => 7,
                    'description' => 'Days a fully deprovisioned domain name remains reserved before another account can claim it.',
                    'rules' => ['required', 'integer', 'between:1,365'],
                ],
            ],
        ],
        'revision_history' => [
            'label' => 'Configuration history',
            'description' => 'Bounded retention for derived edge snapshots and artifacts. Revision numbers remain monotonic and are never reused.',
            'fields' => [
                'retention_days' => [
                    'type' => 'integer', 'label' => 'Revision retention (days)', 'default' => 1,
                    'description' => 'Keep every validated edge revision created within this many days.',
                    'rules' => ['required', 'integer', 'between:1,365'],
                ],
                'minimum_revisions_per_domain' => [
                    'type' => 'integer', 'label' => 'Minimum revisions per domain', 'default' => 10,
                    'description' => 'Always keep at least this many recent rollback points per domain, even after the time window expires.',
                    'rules' => ['required', 'integer', 'between:2,100'],
                ],
            ],
        ],
        'rate_limits' => [
            'label' => 'API rate limits',
            'description' => 'Per-identity request limits that protect interactive and runtime API lanes.',
            'fields' => [
                'login_per_minute' => ['type' => 'integer', 'label' => 'Login attempts per minute', 'default' => 10, 'description' => 'Maximum login attempts per source IP and normalized account identifier each minute.', 'rules' => ['required', 'integer', 'between:1,1000']],
                'account_reads_per_minute' => ['type' => 'integer', 'label' => 'Account reads per minute', 'default' => 600, 'description' => 'Maximum authenticated read requests per user or token each minute.', 'rules' => ['required', 'integer', 'between:1,10000']],
                'account_mutations_per_minute' => ['type' => 'integer', 'label' => 'Account mutations per minute', 'default' => 240, 'description' => 'Maximum authenticated write requests per user or token each minute.', 'rules' => ['required', 'integer', 'between:1,5000']],
                'bulk_per_minute' => ['type' => 'integer', 'label' => 'Bulk requests per minute', 'default' => 12, 'description' => 'Maximum bulk imports or bulk mutation requests per user or token each minute.', 'rules' => ['required', 'integer', 'between:1,1000']],
                'origin_tests_per_minute' => ['type' => 'integer', 'label' => 'Origin tests per minute', 'default' => 20, 'description' => 'Maximum asynchronous origin connectivity tests per user or token each minute.', 'rules' => ['required', 'integer', 'between:1,1000']],
                'edge_registrations_per_hour' => ['type' => 'integer', 'label' => 'Edge registrations per hour', 'default' => 10, 'description' => 'Maximum edge enrollment attempts accepted from one source IP each hour.', 'rules' => ['required', 'integer', 'between:1,1000']],
                'edge_agent_per_minute' => ['type' => 'integer', 'label' => 'Edge agent requests per minute', 'default' => 600, 'description' => 'Maximum authenticated heartbeat and artifact requests per edge identity each minute.', 'rules' => ['required', 'integer', 'between:1,10000']],
            ],
        ],
        'edge_runtime' => [
            'label' => 'Edge runtime',
            'description' => 'Bounds controlling edge health, placement transitions, and generated artifacts.',
            'fields' => [
                'heartbeat_fresh_seconds' => ['type' => 'integer', 'label' => 'Heartbeat freshness (seconds)', 'default' => 45, 'description' => 'An edge is routable only while its most recent authenticated heartbeat is within this window.', 'rules' => ['required', 'integer', 'between:10,300']],
                'placement_drain_seconds' => ['type' => 'integer', 'label' => 'Placement drain time (seconds)', 'default' => 300, 'description' => 'Minimum overlap after a target placement is active before the previous placement may drain.', 'rules' => ['required', 'integer', 'between:30,86400']],
                'max_domain_artifact_bytes' => ['type' => 'integer', 'label' => 'Maximum domain artifact bytes', 'default' => 2097152, 'description' => 'Hard byte limit for one canonical per-domain edge artifact before signing and distribution.', 'rules' => ['required', 'integer', 'between:65536,16777216']],
            ],
        ],
        'origin_safety' => [
            'label' => 'Origin destination safety',
            'description' => 'Additional network policy applied in both the control plane and edge agent after DNS resolution.',
            'fields' => [
                'private_origin_allowlist' => ['type' => 'cidr_list', 'label' => 'Allowed private origin networks', 'default' => [], 'description' => 'Narrow private IPv4 or IPv6 CIDRs that may be used as origins. This can never allow loopback, link-local, metadata, multicast, or reserved destinations.', 'rules' => ['present', 'array', 'max:128']],
                'blocked_origin_networks' => ['type' => 'cidr_list', 'label' => 'Additional blocked origin networks', 'default' => [], 'description' => 'Additional IPv4 or IPv6 CIDRs rejected as origin destinations on top of the built-in non-bypassable safety policy.', 'rules' => ['present', 'array', 'max:128']],
                'blocked_origin_addresses' => ['type' => 'ip_list', 'label' => 'Additional blocked origin addresses', 'default' => [], 'description' => 'Individual IPv4 or IPv6 addresses rejected as origin destinations in addition to edge and platform listener addresses.', 'rules' => ['present', 'array', 'max:128']],
            ],
        ],
        'proxy_defaults' => [
            'label' => 'Proxy defaults',
            'description' => 'Defaults copied into the runtime snapshot when a domain has not supplied an explicit proxy value.',
            'fields' => [
                'enabled' => ['type' => 'boolean', 'label' => 'Proxy enabled by default', 'default' => true, 'description' => 'Enable edge proxy serving by default for active domains with proxied hostnames.', 'rules' => ['required', 'boolean']],
                'redirect_https' => ['type' => 'boolean', 'label' => 'Redirect HTTP to HTTPS', 'default' => false, 'description' => 'Redirect plain HTTP requests to HTTPS by default after a valid certificate is available.', 'rules' => ['required', 'boolean']],
                'http_versions' => ['type' => 'choice_list', 'label' => 'Default HTTP versions', 'default' => ['1.1', '2'], 'description' => 'HTTP protocol versions advertised and accepted by the edge runtime for domains without an override.', 'rules' => ['required', 'array', 'min:1', 'max:2'], 'options' => ['1.1' => 'HTTP/1.1', '2' => 'HTTP/2']],
                'retry_count' => ['type' => 'integer', 'label' => 'Default origin retry count', 'default' => 0, 'description' => 'Retries for a failed idempotent origin connection; bounded to avoid retry amplification.', 'rules' => ['required', 'integer', 'between:0,2']],
            ],
        ],
    ],
];
