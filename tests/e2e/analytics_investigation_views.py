#!/usr/bin/env python3
"""Every analytics focus view of the admin Telemetry page must answer on a real ClickHouse, with and without a status family.

The page shows "Analytics unavailable" when any query of the selected focus fails, so this runs each view's queries against the
development ClickHouse through the application's own store. The queries only read.
"""

import subprocess
import sys

CODE = r"""
use Carbon\CarbonImmutable;
$store = app(App\Support\AnalyticsStore::class);
$to = CarbonImmutable::now('UTC');
$range = ['from' => $to->subHours(24), 'to' => $to];
$raw = ['from' => $to->subHours(24), 'to' => $to, 'raw' => true];
foreach (['traffic', 'cache', 'origin', 'dns'] as $view) {
    foreach ([null, '4xx', '5xx'] as $family) {
        try {
            $tables = $store->investigation(null, $range, $raw, $view, $family);
            echo "ok {$view} " . ($family ?? 'all') . ' ' . count($tables) . "\n";
        } catch (Throwable $error) {
            echo "FAIL {$view} " . ($family ?? 'all') . ' ' . get_class($error) . "\n";
        }
    }
}
"""


def main() -> None:
    result = subprocess.run(["docker", "compose", "-f", "compose.dev.yml", "exec", "-T", "core", "php", "artisan", "tinker", "--execute", CODE],
                            capture_output=True, text=True, check=False, timeout=300)
    lines = [line for line in result.stdout.splitlines() if line.startswith(("ok ", "FAIL "))]
    failed = [line for line in lines if line.startswith("FAIL")]
    if len(lines) != 12 or failed:
        sys.exit(f"analytics focus views failed on ClickHouse: {failed or result.stdout + result.stderr}")
    print("e2e analytics investigation views passed")


if __name__ == "__main__":
    main()
