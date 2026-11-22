"""The WAF request-body reader must judge a body by what Nginx delivers, not by the Content-Length header.

The reader (docker/openresty/waf_body.lua) runs against a stub of the ngx API in plain Lua. The framing cases mirror what
OpenResty reports after it decodes the request: an HTTP/1.1 chunked body and an HTTP/2 body arrive with no Content-Length and
leave their decoded entity in a memory buffer or a temporary file. Real Nginx, ModSecurity and HTTP/2 behavior is qualified in
tests/e2e/waf_body_inspection.py.
"""

import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE = ROOT / "docker/openresty"
CEILING = 1048576


def lua_string(value: str) -> str:
    return '"' + "".join("\\%d" % byte for byte in value.encode()) + '"'


def interpreter() -> str | None:
    return next((shutil.which(name) for name in ("luajit", "lua5.1", "lua", "lua5.4") if shutil.which(name)), None)


def lua_table(headers: dict[str, str]) -> str:
    return "{" + ",".join("%s=%s" % (key, lua_string(value)) for key, value in headers.items()) + "}"


@unittest.skipIf(interpreter() is None, "no Lua interpreter available")
class WafBodyAcquisition(unittest.TestCase):
    def run_case(self, *, headers: dict[str, str] | None = None, memory: str | None = None, file: str | None = None,
                 file_missing: bool = False, read_fails: bool = False, limit: int = 1024, calls: int = 1) -> tuple[str, str, int]:
        """Returns the outcome, the body it returned, and how many times Nginx was asked to read the body."""
        with tempfile.TemporaryDirectory() as directory:
            spool = pathlib.Path(directory) / "spool"
            if file is not None:
                spool.write_text(file)
            path = lua_string(str(directory + "/missing" if file_missing else spool)) if (file is not None or file_missing) else "nil"
            script = f"""
package.path = {lua_string(str(MODULE))} .. "/?.lua;" .. package.path
local reads = 0
local headers = {lua_table(headers or {})}
ngx = {{
  ctx = {{}},
  var = setmetatable({{}}, {{__index = function(_, key) return headers[key] end}}),
  req = {{
    read_body = function() reads = reads + 1; if {str(read_fails).lower()} then error("failed to read request body") end end,
    get_body_data = function() return {lua_string(memory) if memory is not None else "nil"} end,
    get_body_file = function() return {path} end,
  }},
}}
local reader = require "waf_body"
local outcome, body
for _ = 1, {calls} do outcome, body = reader.get({limit}) end
io.write(outcome, "\\n", body or "", "\\n", reads)
"""
            run = pathlib.Path(directory) / "run.lua"
            run.write_text(script)
            result = subprocess.run([interpreter(), str(run)], capture_output=True, text=True, cwd=directory)
        self.assertEqual(result.returncode, 0, result.stderr)
        outcome, _, rest = result.stdout.partition("\n")
        body, _, reads = rest.rpartition("\n")
        return outcome, body, int(reads)

    XSS = "q=%3Cscript%3Ealert(1)%3C%2Fscript%3E"

    def test_a_body_with_content_length_in_memory_is_complete(self):
        self.assertEqual(self.run_case(headers={"http_content_length": str(len(self.XSS))}, memory=self.XSS)[:2], ("complete", self.XSS))

    def test_a_chunked_body_without_content_length_in_memory_is_complete(self):
        outcome, body, _ = self.run_case(headers={"http_transfer_encoding": "chunked"}, memory=self.XSS)
        self.assertEqual((outcome, body), ("complete", self.XSS))

    def test_an_http2_body_without_content_length_or_transfer_encoding_is_complete(self):
        outcome, body, _ = self.run_case(memory=self.XSS)
        self.assertEqual((outcome, body), ("complete", self.XSS))

    def test_a_body_spooled_to_a_temporary_file_is_complete_whatever_the_framing(self):
        for headers in ({"http_content_length": str(len(self.XSS))}, {"http_transfer_encoding": "chunked"}, {}):
            with self.subTest(headers=headers):
                outcome, body, _ = self.run_case(headers=headers, file=self.XSS)
                self.assertEqual((outcome, body), ("complete", self.XSS))

    def test_the_whole_body_is_returned_not_a_prefix(self):
        body = "a=1&" * 200 + self.XSS
        outcome, got, _ = self.run_case(headers={"http_transfer_encoding": "chunked"}, file=body, limit=CEILING)
        self.assertEqual((outcome, got), ("complete", body))

    def test_a_request_without_a_body_is_none(self):
        self.assertEqual(self.run_case()[0], "none")

    def test_a_declared_empty_body_is_empty_not_none(self):
        self.assertEqual(self.run_case(headers={"http_content_length": "0"})[0], "empty")
        self.assertEqual(self.run_case(headers={"http_transfer_encoding": "chunked"})[0], "empty")
        self.assertEqual(self.run_case(headers={"http_transfer_encoding": "chunked"}, memory="")[0], "empty")

    def test_a_body_over_the_limit_is_too_large_in_memory_and_in_a_file(self):
        body = "x" * 2048
        for source in ({"memory": body}, {"file": body}):
            for headers in ({"http_content_length": "2048"}, {"http_transfer_encoding": "chunked"}, {}):
                with self.subTest(source=list(source), headers=headers):
                    self.assertEqual(self.run_case(headers=headers, limit=1024, **source)[0], "too_large")

    def test_a_declared_length_over_the_ceiling_is_refused_without_reading(self):
        outcome, _, reads = self.run_case(headers={"http_content_length": str(CEILING + 1)}, limit=CEILING)
        self.assertEqual((outcome, reads), ("too_large", 0))

    def test_a_body_file_over_the_ceiling_is_too_large(self):
        self.assertEqual(self.run_case(headers={"http_transfer_encoding": "chunked"}, file="x" * (CEILING + 1), limit=CEILING)[0], "too_large")

    def test_a_temporary_file_that_cannot_be_read_is_unreadable(self):
        self.assertEqual(self.run_case(headers={"http_transfer_encoding": "chunked"}, file="x", file_missing=True)[0], "unreadable")

    def test_a_body_reader_that_fails_is_unreadable(self):
        self.assertEqual(self.run_case(headers={"http_transfer_encoding": "chunked"}, read_fails=True)[0], "unreadable")

    def test_a_body_shorter_than_its_declared_length_is_unreadable(self):
        self.assertEqual(self.run_case(headers={"http_content_length": "100"}, memory="short")[0], "unreadable")

    def test_a_content_encoded_body_is_never_complete(self):
        for encoding in ("gzip", "br", "deflate", " GZIP "):
            with self.subTest(encoding=encoding):
                outcome = self.run_case(headers={"http_transfer_encoding": "chunked", "http_content_encoding": encoding}, memory=self.XSS)[0]
                self.assertEqual(outcome, "unsupported_encoding")
        self.assertEqual(self.run_case(headers={"http_content_encoding": "identity"}, memory=self.XSS)[0], "complete")

    def test_the_body_is_read_once_per_request_and_each_caller_applies_its_own_limit(self):
        outcome, _, reads = self.run_case(memory=self.XSS, limit=1024, calls=3)
        self.assertEqual((outcome, reads), ("complete", 1))
        self.assertEqual(self.run_case(memory="x" * 2000, limit=1500)[0], "too_large")

    def test_only_a_known_body_counts_as_inspectable(self):
        script = f"""package.path = {lua_string(str(MODULE))} .. "/?.lua;" .. package.path
local reader = require "waf_body"
for _, outcome in ipairs({{"none", "empty", "complete"}}) do assert(reader.is_known(outcome), outcome) end
for _, outcome in ipairs({{"too_large", "unreadable", "unsupported_encoding", "scoring_failed", "", "anything"}}) do assert(not reader.is_known(outcome), outcome) end
"""
        with tempfile.TemporaryDirectory() as directory:
            run = pathlib.Path(directory) / "run.lua"
            run.write_text(script)
            result = subprocess.run([interpreter(), str(run)], capture_output=True, text=True, cwd=directory)
        self.assertEqual(result.returncode, 0, result.stderr)


class WafAccessHandlerPolicy(unittest.TestCase):
    """Static guards on runtime.lua: the places the original bypass lived must stay closed."""

    LUA = (MODULE / "runtime.lua").read_text()

    def test_the_content_length_header_no_longer_decides_whether_a_body_is_read(self):
        start = self.LUA.index("local function request_body_text")
        self.assertNotIn("http_content_length", self.LUA[start:self.LUA.index("local function score_request")])

    def test_inline_inspection_is_switched_off_only_where_a_score_exists(self):
        scored = self.LUA.index("request.scores = scores")
        first_off = self.LUA.index('set_waf_mode("off")', self.LUA.index("local scores, failure_outcome"))
        self.assertLess(scored, first_off)
        self.assertEqual(self.LUA.count('set_waf_mode("off")'), 2, "the score and an explicit skip rule only")
        # The mode is written in one place only, so no other path can switch inline inspection off.
        self.assertEqual(self.LUA.count('ngx.req.set_header("X-CDNF-Internal-WAF-Mode"'), 1)

    def test_a_body_that_cannot_be_inspected_is_refused_in_blocking_mode(self):
        self.assertIn('elseif waf_mode == "block" then\n                return security_reject(failure.status, failure.reason)', self.LUA)
        for outcome in ("too_large", "unreadable", "unsupported_encoding"):
            self.assertRegex(self.LUA, outcome + r" = \{status = 4\d\d")

    def test_the_scoring_request_carries_the_length_of_the_decoded_body(self):
        self.assertIn('"Content-Length: " .. tostring(body and #body or 0)', self.LUA)
        self.assertIn('["transfer-encoding"] = true', self.LUA)


if __name__ == "__main__":
    unittest.main()
