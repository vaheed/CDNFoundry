"""The edge runtime's request path runs ModSecurity only where it can act, parses each rule set once, and logs once.

These are configuration invariants of docker/nginx and docker/openresty. They keep the measured costs from returning (see
tests/e2e/edge_cell_benchmark.py) without weakening inspection; tests/e2e/managed_waf.py and tests/e2e/waf_body_inspection.py
qualify the real blocking, fallback and audit behavior.
"""

import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
SERVER = (ROOT / "docker/nginx/edge-runtime.conf").read_text()
NGINX = (ROOT / "docker/nginx/openresty.conf").read_text()
RUNTIME = (ROOT / "docker/openresty/runtime.lua").read_text()
SCORE = (ROOT / "docker/openresty/waf-score.conf").read_text()
INLINE = (ROOT / "docker/openresty/waf.conf").read_text()
ENTRYPOINT = (ROOT / "docker/openresty/entrypoint.sh").read_text()
DOCKERFILE = (ROOT / "docker/openresty/Dockerfile").read_text()
SIZES = ("1m", "10m", "100m", "1g")


def location(name: str) -> str:
    match = re.search(r"\n    location " + re.escape(name) + r" \{\n(.*?)\n    \}\n", SERVER, re.S)
    if match is None:
        raise AssertionError(f"location {name} is missing")
    return match.group(1)


def public_server() -> str:
    start = SERVER.index("listen 8080 default_server")
    return SERVER[start:SERVER.index("\n# The scoring listener.", start)]


class EdgeWafHotPath(unittest.TestCase):
    def test_every_size_has_an_inspecting_and_a_non_inspecting_location(self):
        for size in SIZES:
            inline = location(f"@cache_{size}")
            direct = location(f"@cache_{size}_no_inline_waf")
            self.assertIn("modsecurity on;", inline)
            self.assertNotIn("modsecurity", direct)
            # Both serve the same cache and the same origin hop with the same temporary-file bound.
            for body in (inline, direct):
                self.assertIn("include /etc/nginx/proxy-cache.conf;", body)
                self.assertIn("include /etc/nginx/cache-upstream.conf;", body)
            temp = re.search(r"proxy_max_temp_file_size (\S+);", inline).group(1)
            self.assertIn(f"proxy_max_temp_file_size {temp};", direct)

    def test_the_inline_rule_set_is_declared_once_for_the_public_server(self):
        server = public_server()
        self.assertEqual(server.count("modsecurity_rules_file /etc/cdnfoundry/waf.conf;"), 1)
        self.assertEqual(SERVER.count("modsecurity_rules_file /etc/cdnfoundry/waf.conf;"), 1)
        # Only the inline cache locations switch the engine on; ModSecurity is off by default everywhere else.
        self.assertEqual(server.count("modsecurity on;"), len(SIZES))

    def test_only_a_request_whose_mode_is_off_skips_inline_inspection(self):
        self.assertIn('local inline = ngx.ctx.waf_mode ~= "off" and "" or "_no_inline_waf"', RUNTIME)
        for size in SIZES:
            self.assertIn(f'ngx.exec("@cache_{size}" .. inline)', RUNTIME)
        self.assertNotIn('ngx.exec("@cache_1m")', RUNTIME)
        # The mode a location is chosen by is the mode ModSecurity receives: both are only ever set together.
        self.assertEqual(RUNTIME.count('ngx.req.set_header("X-CDNF-Internal-WAF-Mode"'), 1)
        self.assertIn('ngx.ctx.waf_mode = mode\n    ngx.req.set_header("X-CDNF-Internal-WAF-Mode", mode)', RUNTIME)
        # The inline engine itself still refuses a request that reaches it without a valid mode.
        self.assertIn('"!@rx ^(?:off|monitor|block)$" "id:1000000,phase:1,deny', INLINE)

    def test_the_scoring_listener_skips_response_rules_but_keeps_the_logging_phase(self):
        crs = SCORE.index("Include /etc/cdnfoundry/crs/rules/*.conf")
        self.assertIn("SecResponseBodyAccess Off", SCORE)
        for phase, rule_id in ((3, 1000111), (4, 1000112)):
            rule = f'SecAction "id:{rule_id},phase:{phase},pass,nolog,skipAfter:CDNF_SCORE_RESPONSE_END"'
            self.assertIn(rule, SCORE)
            self.assertLess(SCORE.index(rule), crs, "the skip must come before the CRS rules of its phase")
        self.assertTrue(SCORE.rstrip().endswith("SecMarker CDNF_SCORE_RESPONSE_END"))
        # Switching the engine off would also end the logging phase and drop the sanitized audit summary.
        self.assertNotIn("ctl:ruleEngine=Off", SCORE)

    def test_the_access_log_goes_to_vector_only_unless_asked(self):
        self.assertNotIn("access_log /dev/stdout", NGINX)
        self.assertEqual(NGINX.count("access_log syslog:server=vector:9000,"), 1)
        self.assertIn('stdout_access_log=\'    access_log /dev/stdout edge_json buffer=64k flush=1s;\'', ENTRYPOINT)
        self.assertIn('"${EDGE_ACCESS_LOG_STDOUT:-false}" = true', ENTRYPOINT)
        self.assertIn("EDGE_ACCESS_LOG_STDOUT must be true or false", ENTRYPOINT)

    def test_openresty_runs_with_jemalloc(self):
        self.assertIn(" jemalloc ", DOCKERFILE)
        self.assertIn("test -f /usr/lib/libjemalloc.so.2", DOCKERFILE)
        self.assertIn("export LD_PRELOAD=/usr/lib/libjemalloc.so.2", ENTRYPOINT)
        # An image built before the allocator was added must still start with this entrypoint.
        self.assertIn('[ -f /usr/lib/libjemalloc.so.2 ]; then', ENTRYPOINT)


if __name__ == "__main__":
    unittest.main()
