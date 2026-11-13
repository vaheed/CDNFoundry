<?php

// A stand-in for ClickHouse for the browser suite only. It answers the mitigated-requests query with fixed, awkward rows
// (long values, IPv6, an unknown rule) and honors the country, score, and request id filters. Every other query is
// refused with 503, so the screens that expect an unavailable telemetry store still see one.

$sql = (string) file_get_contents('php://input');
// Security analytics: the same fixed numbers for every domain and range.
$security = [
    'GROUP BY attack_band' => [['attack_band' => 0, 'requests' => 52000, 'mitigated' => 120], ['attack_band' => 1, 'requests' => 640, 'mitigated' => 640], ['attack_band' => 3, 'requests' => 85, 'mitigated' => 85], ['attack_band' => 5, 'requests' => 410000, 'mitigated' => 0]],
    'GROUP BY action' => [['action' => 'allowed', 'requests' => 461000, 'mitigated' => 0], ['action' => 'blocked', 'requests' => 725, 'mitigated' => 725], ['action' => 'logged', 'requests' => 100, 'mitigated' => 100]],
    'GROUP BY country' => [['country' => 'US', 'requests' => 300000, 'mitigated' => 540], ['country' => 'DE', 'requests' => 160000, 'mitigated' => 285]],
    'GROUP BY asn' => [['asn' => 15169, 'requests' => 300000, 'mitigated' => 540], ['asn' => 0, 'requests' => 900, 'mitigated' => 20]],
    'GROUP BY path' => [['path' => '/wp-login.php', 'requests' => 400, 'mitigated' => 400], ['path' => '/', 'requests' => 9000, 'mitigated' => 3]],
    'GROUP BY user_agent' => [['user_agent' => 'sqlmap/1.7.2#stable (https://sqlmap.org)', 'requests' => 320, 'mitigated' => 320], ['user_agent' => '', 'requests' => 15, 'mitigated' => 2]],
];
foreach ($security as $marker => $rows) {
    if (str_contains($sql, $marker) && (str_contains($sql, 'cdnf.security_hourly') || str_contains($sql, "event_type = 'request'"))) {
        foreach ($rows as $row) {
            echo json_encode($row)."\n";
        }

        return;
    }
}
if (! str_contains($sql, 'FROM cdnf.edge_events') || ! str_contains($sql, "security_rule != ''")) {
    http_response_code(503);
    echo 'unavailable';

    return;
}
$ago = fn (int $minutes): string => gmdate('Y-m-d H:i:s.000', time() - $minutes * 60);
$rows = [
    ['occurred_at' => $ago(3), 'event_id' => '11111111-1111-4111-8111-111111111111', 'hostname' => 'alpha-browser.example', 'method' => 'GET', 'path' => '/admin/login', 'status' => 403, 'security_action' => 'block', 'security_reason' => 'custom_rule_block', 'security_rule' => 'r1', 'attack_score' => 0, 'country' => 'DE', 'asn' => 64500, 'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 with a very long suffix to check truncation in the table', 'request_id' => 'aaaaaaaaaaaa0001', 'client_ip' => '203.0.113.77'],
    ['occurred_at' => $ago(9), 'event_id' => '22222222-2222-4222-8222-222222222222', 'hostname' => 'alpha-browser.example', 'method' => 'GET', 'path' => "/search?q=1'%20UNION%20SELECT%20username,password%20FROM%20users--%20and%20a%20deliberately%20long%20query%20string%20that%20keeps%20going%20beyond%20the%20column%20width", 'status' => 403, 'security_action' => 'block', 'security_reason' => 'waf_block', 'security_rule' => 'waf', 'attack_score' => 4, 'country' => 'US', 'asn' => 15169, 'user_agent' => 'sqlmap/1.7.2#stable (https://sqlmap.org)', 'request_id' => 'aaaaaaaaaaaa0002', 'client_ip' => '2001:db8:abcd:12::77'],
    ['occurred_at' => $ago(14), 'event_id' => '33333333-3333-4333-8333-333333333333', 'hostname' => 'alpha-browser.example', 'method' => 'POST', 'path' => '/wp-login.php', 'status' => 403, 'security_action' => 'block', 'security_reason' => 'platform_rule_block', 'security_rule' => 'p1', 'attack_score' => 0, 'country' => 'FR', 'asn' => 0, 'user_agent' => '', 'request_id' => 'aaaaaaaaaaaa0003', 'client_ip' => '198.51.100.9'],
    ['occurred_at' => $ago(22), 'event_id' => '44444444-4444-4444-8444-444444444444', 'hostname' => 'alpha-browser.example', 'method' => 'GET', 'path' => '/old', 'status' => 200, 'security_action' => 'allow', 'security_reason' => '', 'security_rule' => 'r999', 'attack_score' => 61, 'country' => 'ZZ', 'asn' => 0, 'user_agent' => 'curl/8.16.0', 'request_id' => 'aaaaaaaaaaaa0004', 'client_ip' => '192.0.2.1'],
];
parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);
$rows = array_values(array_filter($rows, fn (array $row): bool => (! isset($query['param_country']) || $row['country'] === $query['param_country'])
    && (! isset($query['param_request_id']) || $row['request_id'] === $query['param_request_id'])
    && (! isset($query['param_score_max']) || ($row['attack_score'] >= 1 && $row['attack_score'] <= (int) $query['param_score_max']))));
foreach ($rows as $row) {
    echo json_encode($row)."\n";
}
