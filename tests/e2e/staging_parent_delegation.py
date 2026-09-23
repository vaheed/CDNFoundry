#!/usr/bin/env python3
"""Read-only public parent-delegation check for a protected staging domain record."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import subprocess


ROOT = Path(__file__).resolve().parents[2]
PHP = r"""
require getenv('CDNF_QUALIFICATION_CORE').'/vendor/autoload.php';
$app = require getenv('CDNF_QUALIFICATION_CORE').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $observed = (new App\Support\NameserverResolver)->resolve(getenv('CDNF_QUALIFICATION_DOMAIN'));
    $expected = json_decode(getenv('CDNF_QUALIFICATION_NAMESERVERS'), true, 512, JSON_THROW_ON_ERROR);
    sort($expected);
    echo json_encode(['status' => $observed === $expected ? 'passed' : 'mismatch',
        'expected_count' => count($expected), 'observed_count' => count($observed)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'failed', 'exception' => get_class($exception)], JSON_THROW_ON_ERROR);
}
"""


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("inventory", type=Path, help="protected domain record with name and assigned_nameservers")
    args = parser.parse_args()
    record = json.loads(args.inventory.read_text())
    domain = record["name"]
    nameservers = record["assigned_nameservers"]
    if not isinstance(domain, str) or not isinstance(nameservers, list) or not 2 <= len(nameservers) <= 16:
        raise ValueError("Invalid staging domain record")
    if any(not isinstance(name, str) for name in nameservers):
        raise ValueError("Invalid nameserver assignment")

    environment = os.environ.copy()
    environment.update(
        CDNF_QUALIFICATION_CORE=str(ROOT / "core"),
        CDNF_QUALIFICATION_DOMAIN=domain,
        CDNF_QUALIFICATION_NAMESERVERS=json.dumps(sorted(set(nameservers))),
    )
    result = subprocess.run(["php", "-r", PHP], cwd=ROOT, env=environment,
                            capture_output=True, text=True, timeout=60)
    if result.returncode != 0:
        print(json.dumps({"qualification": "public_parent_delegation", "status": "harness_failed",
                          "exit_code": result.returncode}, sort_keys=True))
        return 1
    outcome = json.loads(result.stdout)
    print(json.dumps({"qualification": "public_parent_delegation", **outcome}, sort_keys=True))
    return 0 if outcome["status"] == "passed" else 1


if __name__ == "__main__":
    raise SystemExit(main())
