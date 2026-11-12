<?php

return [
    'groups' => [
        'display' => [
            'label' => 'Display',
            'description' => 'Shared date and time presentation for administrator and domain-user panels. Stored timestamps and API values remain UTC.',
            'fields' => [
                'timezone' => ['type' => 'timezone', 'label' => 'Display timezone', 'default' => 'UTC', 'description' => 'One IANA timezone for all panel dates, charts and date/time inputs.', 'rules' => ['required', 'string', 'timezone:all']],
            ],
        ],
        'operations' => [
            'label' => 'Operations and recovery',
            'description' => 'Bounded retention and freshness thresholds used by health checks, alerts, and recovery policy.',
            'fields' => [
                'audit_retention_days' => ['type' => 'integer', 'label' => 'Audit retention (days)', 'default' => 365, 'description' => 'Delete audit events older than this period in bounded daily batches.', 'rules' => ['required', 'integer', 'between:30,3650']],
                'security_event_retention_days' => ['type' => 'integer', 'label' => 'Security event retention (days)', 'default' => 30, 'description' => 'Delete edge-reported security events older than this period in bounded daily batches. Events are kept once per domain, edge, reason, and hour.', 'rules' => ['required', 'integer', 'between:7,365']],
                'operation_retention_days' => ['type' => 'integer', 'label' => 'Finished operation retention (days)', 'default' => 90, 'description' => 'Delete finished operations older than this period in bounded daily batches. Pending, running, and unacknowledged failed operations are kept.', 'rules' => ['required', 'integer', 'between:7,3650']],
                'scheduler_stale_seconds' => ['type' => 'integer', 'label' => 'Scheduler stale threshold (seconds)', 'default' => 180, 'description' => 'Mark scheduler health degraded when its durable heartbeat is older than this threshold.', 'rules' => ['required', 'integer', 'between:60,3600']],
                'clock_drift_warning_seconds' => ['type' => 'integer', 'label' => 'Clock drift warning (seconds)', 'default' => 5, 'description' => 'Maximum accepted host clock offset for external monitoring and qualification.', 'rules' => ['required', 'integer', 'between:1,300']],
                'failed_operations_window_minutes' => ['type' => 'integer', 'label' => 'Failed operations window (minutes)', 'default' => 60, 'description' => 'Failed operations count toward the failed-operations alert and dashboards for this long after they fail, then the alert resolves.', 'rules' => ['required', 'integer', 'between:5,1440']],
                'backup_stale_hours' => ['type' => 'integer', 'label' => 'Backup stale threshold (hours)', 'default' => 26, 'description' => 'Maximum age of the most recent verified encrypted off-host control database backup.', 'rules' => ['required', 'integer', 'between:1,168']],
            ],
        ],
        'telemetry' => [
            'label' => 'Telemetry retention and privacy',
            'description' => 'Bounded raw telemetry, aggregate retention, finalization delay, and client-address masking.',
            'fields' => [
                'raw_retention_days' => ['type' => 'integer', 'label' => 'Raw retention (days)', 'default' => 7, 'description' => 'Maximum time raw request and DNS events remain queryable.', 'rules' => ['required', 'integer', 'between:1,30']],
                'hourly_retention_days' => ['type' => 'integer', 'label' => 'Hourly retention (days)', 'default' => 400, 'description' => 'Retention for hourly operational aggregates.', 'rules' => ['required', 'integer', 'between:30,730']],
                'daily_retention_days' => ['type' => 'integer', 'label' => 'Daily retention (days)', 'default' => 1095, 'description' => 'Retention for compact daily aggregates.', 'rules' => ['required', 'integer', 'between:365,3650']],
                'one_minute_retention_hours' => ['type' => 'integer', 'label' => '1-minute rollup retention (hours)', 'default' => 48, 'description' => 'Retention for the 1-minute rollup that serves live and NOC panels.', 'rules' => ['required', 'integer', 'between:12,168']],
                'prometheus_retention_days' => ['type' => 'integer', 'label' => 'Prometheus retention (days)', 'default' => 30, 'description' => 'Retention of Prometheus metrics. Recording rules keep long-range queries fast.', 'rules' => ['required', 'integer', 'between:7,365']],
                'loki_retention_days' => ['type' => 'integer', 'label' => 'Loki retention (days)', 'default' => 14, 'description' => 'Retention of operational logs in Loki.', 'rules' => ['required', 'integer', 'between:1,90']],
                'finalization_delay_minutes' => ['type' => 'integer', 'label' => 'Finalization delay (minutes)', 'default' => 15, 'description' => 'Intervals newer than this delay are labelled provisional.', 'rules' => ['required', 'integer', 'between:5,1440']],
                'ipv4_mask_bits' => ['type' => 'integer', 'label' => 'IPv4 display mask', 'default' => 24, 'description' => 'Prefix retained in normal views and exports.', 'rules' => ['required', 'integer', 'between:8,32']],
                'ipv6_mask_bits' => ['type' => 'integer', 'label' => 'IPv6 display mask', 'default' => 48, 'description' => 'Prefix retained in normal views and exports.', 'rules' => ['required', 'integer', 'between:16,64']],
            ],
        ],
        'observability' => [
            'label' => 'Observability links',
            'description' => 'Optional administrator-facing links to external observability tools. These links never participate in serving traffic.',
            'fields' => [
                'grafana_explore_url' => [
                    'type' => 'url',
                    'label' => 'Grafana Explore URL',
                    'default' => null,
                    'description' => 'Optional absolute HTTP or HTTPS Grafana Explore address used by the Live Logs navigation item. Leave empty to use GRAFANA_EXPLORE_URL when configured.',
                    'rules' => ['nullable', 'string', 'url:http,https', 'max:2048'],
                ],
            ],
        ],
        'domain_monitoring' => [
            'label' => 'Domain monitoring',
            'description' => 'Scheduled nameserver re-verification and registration expiry checks. Both run in small bounded batches.',
            'fields' => [
                'nameserver_enforcement' => [
                    'type' => 'boolean', 'label' => 'Un-verify domains whose nameservers changed', 'default' => true,
                    'description' => 'When off, changes are only recorded in the audit log and the domain stays active.',
                    'rules' => ['required', 'boolean'],
                ],
                'active_check_hours' => [
                    'type' => 'integer', 'label' => 'Active domain check interval (hours)', 'default' => 12,
                    'description' => 'How often each active domain nameserver delegation is re-checked.',
                    'rules' => ['required', 'integer', 'between:1,24'],
                ],
                'mismatch_confirmations' => [
                    'type' => 'integer', 'label' => 'Confirmed mismatches before un-verifying', 'default' => 3,
                    'description' => 'Consecutive checks that must show a different delegation. A lookup failure never counts.',
                    'rules' => ['required', 'integer', 'between:1,10'],
                ],
                'confirmation_spacing_minutes' => [
                    'type' => 'integer', 'label' => 'Spacing between confirmations (minutes)', 'default' => 60,
                    'description' => 'Time between the checks that confirm a changed delegation.',
                    'rules' => ['required', 'integer', 'between:5,720'],
                ],
                'registration_check_days' => [
                    'type' => 'integer', 'label' => 'Registration expiry check interval (days)', 'default' => 7,
                    'description' => 'How often each domain registration expiry is looked up. Domains close to expiry are checked daily.',
                    'rules' => ['required', 'integer', 'between:1,30'],
                ],
                'registration_warning_days' => [
                    'type' => 'integer', 'label' => 'Registration expiry warning (days)', 'default' => 30,
                    'description' => 'Panels highlight a registration that expires within this many days, and users are reminded.',
                    'rules' => ['required', 'integer', 'between:1,90'],
                ],
            ],
        ],
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
        'notifications' => [
            'label' => 'Notifications',
            'description' => 'Delivery limits for Telegram, Discord, Slack, and webhook notifications sent by the notifier. Administrator channels always work; this group governs domain-user channels and bounds.',
            'fields' => [
                'domain_user_channels' => ['type' => 'boolean', 'label' => 'Domain users can add notification channels', 'default' => false, 'description' => 'When on, a domain user adds Telegram, Discord, or Slack channels and receives events for assigned domains only. When off, existing user channels stop sending and are kept.', 'rules' => ['required', 'boolean']],
                'max_channels_per_user' => ['type' => 'integer', 'label' => 'Channels per user', 'default' => 5, 'description' => 'Maximum notification channels one domain user may keep.', 'rules' => ['required', 'integer', 'between:1,20']],
                'max_platform_channels' => ['type' => 'integer', 'label' => 'Platform channels', 'default' => 20, 'description' => 'Maximum administrator channels for the whole platform.', 'rules' => ['required', 'integer', 'between:1,100']],
                'channel_messages_per_minute' => ['type' => 'integer', 'label' => 'Messages per channel per minute', 'default' => 6, 'description' => 'Further events inside the minute are merged into one digest message sent when the minute ends.', 'rules' => ['required', 'integer', 'between:1,60']],
                'pause_after_failures' => ['type' => 'integer', 'label' => 'Pause after consecutive failures', 'default' => 10, 'description' => 'A channel whose deliveries fail this many times in a row is paused and its owner sees why. Resume it after fixing the destination.', 'rules' => ['required', 'integer', 'between:3,100']],
                'origin_error_percent' => ['type' => 'integer', 'label' => 'Origin error threshold (percent)', 'default' => 20, 'description' => 'A domain whose requests failed at the origin at or above this share over five minutes sends one "origin errors" notification, then waits an hour.', 'rules' => ['required', 'integer', 'between:1,100']],
                'origin_error_min_requests' => ['type' => 'integer', 'label' => 'Origin error minimum requests', 'default' => 100, 'description' => 'A domain needs at least this many requests in the five minutes before its origin error share is judged.', 'rules' => ['required', 'integer', 'between:10,100000']],
            ],
        ],
        'log_push' => [
            'label' => 'Log push',
            'description' => 'Lets domain users send the logs of their own domains to an endpoint they control. Central platform logs are not affected.',
            'fields' => [
                'domain_user_log_push' => ['type' => 'boolean', 'label' => 'Domain users can push logs to their own endpoint', 'default' => false, 'description' => 'When off, every push stops within a minute and the destinations are kept.', 'rules' => ['required', 'boolean']],
                'max_destinations_per_domain' => ['type' => 'integer', 'label' => 'Destinations per domain', 'default' => 2, 'description' => 'Maximum log push destinations one domain may have.', 'rules' => ['required', 'integer', 'between:1,5']],
                'max_destinations' => ['type' => 'integer', 'label' => 'Destinations on the platform', 'default' => 2000, 'description' => 'Maximum log push destinations across all domains. It bounds the worker time spent each minute.', 'rules' => ['required', 'integer', 'between:1,20000']],
                'batch_rows' => ['type' => 'integer', 'label' => 'Rows per batch', 'default' => 5000, 'description' => 'Maximum rows in one delivery. A batch is compressed before it is sent.', 'rules' => ['required', 'integer', 'between:100,50000']],
                'max_batches_per_run' => ['type' => 'integer', 'label' => 'Batches per destination per minute', 'default' => 5, 'description' => 'A destination that is behind catches up over several minutes instead of holding the worker.', 'rules' => ['required', 'integer', 'between:1,20']],
                'failure_notify_after' => ['type' => 'integer', 'label' => 'Notify after failures', 'default' => 5, 'description' => 'A destination that fails this many deliveries in a row sends one "log push failing" notification.', 'rules' => ['required', 'integer', 'between:1,100']],
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
                'promotion_quorum_percent' => ['type' => 'integer', 'label' => 'Promotion quorum (percent)', 'default' => 100, 'description' => 'Share of healthy participating PoPs that must acknowledge a placement before it is promoted. At least one always must.', 'rules' => ['required', 'integer', 'between:1,100']],
                'promotion_grace_seconds' => ['type' => 'integer', 'label' => 'Promotion grace (seconds)', 'default' => 120, 'description' => 'After this long in deploying, a PoP that has not acknowledged is lagging: it stops counting toward the quorum and catches up on its own.', 'rules' => ['required', 'integer', 'between:30,3600']],
                'probe_min_vantage_points' => ['type' => 'integer', 'label' => 'Probe vantage points', 'default' => 2, 'description' => 'Other PoPs that must report on a PoP before failed probes can withdraw it from DNS. A fleet with fewer never withdraws a PoP on probes.', 'rules' => ['required', 'integer', 'between:2,20']],
                'probe_result_max_age_seconds' => ['type' => 'integer', 'label' => 'Probe result age (seconds)', 'default' => 180, 'description' => 'A probe result older than this no longer counts toward the quorum.', 'rules' => ['required', 'integer', 'between:30,3600']],
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
                'diagnostic_response_headers' => ['type' => 'boolean', 'label' => 'Expose diagnostic response headers', 'default' => false, 'description' => 'When on, proxied responses carry the X-CDNF-Cache, X-CDNF-Origin, X-CDNF-Origin-Transition, X-CDNF-Error and X-CDNF-Security-Reason headers. When off, these headers and the Server header are removed from every response. Applies to all domains. The edge access log and telemetry are unaffected.', 'rules' => ['required', 'boolean']],
                'retry_count' => ['type' => 'integer', 'label' => 'Default origin retry count', 'default' => 0, 'description' => 'Retries for a failed idempotent origin connection; bounded to avoid retry amplification.', 'rules' => ['required', 'integer', 'between:0,2']],
            ],
        ],
    ],
];
