<?php

return [
    'artifact_signing_key' => env('EDGE_ARTIFACT_SIGNING_KEY', env('APP_KEY')),
    'heartbeat_fresh_seconds' => (int) env('EDGE_HEARTBEAT_FRESH_SECONDS', 45),
    'placement_drain_seconds' => (int) env('EDGE_PLACEMENT_DRAIN_SECONDS', 300),
    'private_origin_allowlist' => array_values(array_filter(explode(',', env('EDGE_PRIVATE_ORIGIN_ALLOWLIST', '')))),
    'blocked_origin_networks' => array_values(array_filter(explode(',', env('EDGE_BLOCKED_ORIGIN_NETWORKS', '')))),
];
