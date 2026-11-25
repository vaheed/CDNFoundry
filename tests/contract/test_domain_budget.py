"""One domain's high-cardinality traffic must never evict another domain's rate-limit state.

The budget module (docker/openresty/domain_budget.lua) runs against a stub of a shared dictionary that behaves like Nginx's:
when it is full, adding a key silently evicts the least recently used entry. Real eviction on a real cell is qualified in
tests/e2e/rate_limit_isolation.py.
"""

import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE = ROOT / "docker/openresty"

PRELUDE = f"""
package.path = "{MODULE}/?.lua;" .. package.path
local now = 1000
ngx = {{ time = function() return now end }}
function advance(seconds) now = now + seconds end

-- A shared dictionary that holds `slots` entries and evicts the least recently used one to make room, as Nginx does.
function dictionary(slots, bytes)
  local d, tick, values, expires, used = {{}}, 0, {{}}, {{}}, {{}}
  local scanned = nil
  local function expire() if scanned == now then return end scanned = now for k, e in pairs(expires) do if e <= now then values[k], expires[k], used[k] = nil, nil, nil end end end
  local function count() local n = 0 for _ in pairs(values) do n = n + 1 end return n end
  function d:capacity() return bytes end
  function d:get(k) expire(); if values[k] ~= nil then tick = tick + 1; used[k] = tick end return values[k] end
  function d:set(k, v, ttl)
    expire()
    if values[k] == nil and count() >= slots then
      local oldest for key, t in pairs(used) do if not oldest or t < used[oldest] then oldest = key end end
      values[oldest], expires[oldest], used[oldest] = nil, nil, nil
    end
    tick = tick + 1; values[k], used[k] = v, tick; expires[k] = ttl and ttl > 0 and now + ttl or math.huge
    return true
  end
  function d:incr(k, by, init, ttl)
    expire()
    if values[k] == nil then self:set(k, init, ttl) end
    values[k] = values[k] + by; tick = tick + 1; used[k] = tick
    return values[k]
  end
  function d:entries() expire(); return count() end
  return d
end
local budget = require "domain_budget"
"""


def interpreter() -> str | None:
    return next((shutil.which(name) for name in ("luajit", "lua5.1", "lua", "lua5.4") if shutil.which(name)), None)


@unittest.skipIf(interpreter() is None, "no Lua interpreter available")
class DomainBudget(unittest.TestCase):
    def lua(self, body: str) -> str:
        with tempfile.TemporaryDirectory() as directory:
            script = pathlib.Path(directory) / "run.lua"
            script.write_text(PRELUDE + body)
            result = subprocess.run([interpreter(), str(script)], capture_output=True, text=True, cwd=directory)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result.stdout.strip()

    def test_a_domain_share_is_an_equal_part_of_the_budgeted_capacity_with_a_floor(self):
        out = self.lua("""
local d = dictionary(100, 1048576)            -- 1 MiB / 256 bytes * 0.5 = 2048 entries
print(budget.share(d, 1), budget.share(d, 4), budget.share(d, 16), budget.share(d, 100000), budget.share(d, 0))
""")
        self.assertEqual(out.split(), ["2048", "512", "128", "100", "2048"])

    def test_entries_are_admitted_up_to_the_limit_then_refused_and_the_window_resets(self):
        out = self.lua("""
local d = dictionary(100, 1048576)
local admitted, refused, last = 0, 0, 0
for _ = 1, 10 do
  local ok, used = budget.admit(d, 7, "c60", 60, 4)
  if ok then admitted = admitted + 1 else refused = refused + 1 end
  last = used
end
advance(60)
local again, used_again = budget.admit(d, 7, "c60", 60, 4)
print(admitted, refused, last, tostring(again), used_again)
""")
        self.assertEqual(out.split(), ["4", "6", "10", "true", "1"])

    def test_each_domain_and_kind_has_its_own_count(self):
        out = self.lua("""
local d = dictionary(100, 1048576)
for _ = 1, 3 do budget.admit(d, 1, "c60", 60, 3) end
local other_domain = budget.admit(d, 2, "c60", 60, 3)
local other_kind = budget.admit(d, 1, "m600", 600, 3)
local first_refused = budget.admit(d, 1, "c60", 60, 3)
print(tostring(other_domain), tostring(other_kind), tostring(first_refused))
""")
        self.assertEqual(out.split(), ["true", "true", "false"])

    def test_a_key_flood_on_one_domain_evicts_nothing_of_another_domain_when_budgets_are_used(self):
        # 2048 slots: the budget covers half of them, shared by two domains, so the flood cannot fill the dictionary.
        out = self.lua("""
local d = dictionary(2048, 2048 * 256)
local share = budget.share(d, 2)                                  -- 512 entries per domain
d:set("m:victim", true, 3600)                                     -- the other domain's mitigation marker
for i = 1, 300 do d:set("c:victim:" .. i, 1, 120) end             -- ...and its counters, within its own share
for i = 1, 3000 do
  if budget.admit(d, "attacker", "c60", 60, share) then d:incr("c:attacker:" .. i, 1, 0, 120) end
end
local survivors = 0
for i = 1, 300 do if d:get("c:victim:" .. i) then survivors = survivors + 1 end end
print(tostring(d:get("m:victim")), survivors, d:entries() < 2048)
""")
        self.assertEqual(out.split(), ["true", "300", "true"])

    def test_without_a_budget_the_same_flood_evicts_the_other_domains_marker(self):
        out = self.lua("""
local d = dictionary(2048, 2048 * 256)
d:set("m:victim", true, 3600)
for i = 1, 3000 do d:incr("c:attacker:" .. i, 1, 0, 120) end
print(tostring(d:get("m:victim")))
""")
        self.assertEqual(out, "nil")

    def test_budgeted_admissions_never_fill_the_dictionary(self):
        out = self.lua("""
local d = dictionary(4096, 4096 * 256)
local hosts = 8
local share = budget.share(d, hosts)
for domain = 1, hosts do
  for i = 1, 20000 do
    if budget.admit(d, domain, "c60", 60, share) then d:incr("c:" .. domain .. ":" .. i, 1, 0, 120) end
  end
end
print(d:entries() <= 4096 * budget.BUDGET_FRACTION + hosts, d:entries())
""")
        self.assertTrue(out.startswith("true"), out)


class RuntimeUsesBudgets(unittest.TestCase):
    LUA = (MODULE / "runtime.lua").read_text()

    def test_every_new_rate_rule_counter_and_client_counter_is_admitted_against_a_domain_share(self):
        self.assertIn('domain_budget.admit(dictionary, domain, "c" .. period, period,', self.LUA)
        self.assertIn('domain_budget.admit(dictionary, domain, "m" .. width, width,', self.LUA)
        self.assertIn('domain_budget.admit(traffic, config.domain, "q", 1,', self.LUA)

    def test_a_refused_counter_is_reported_once_per_window(self):
        self.assertEqual(self.LUA.count('record_security_event("rate_limit_state_full")'), 3)

    def test_a_key_refused_a_counter_is_held_to_the_rule_threshold_by_one_overflow_counter(self):
        self.assertIn("if overflow then return rate_overflow_exceeded(dictionary, domain, rule, threshold, period) end", self.LUA)
        self.assertIn("if not count then return rate_overflow_exceeded(dictionary, domain, rule, threshold, period) end", self.LUA)
        self.assertIn('"o:" .. domain .. ":" .. tostring(rule.id)', self.LUA)
        self.assertIn("return count > threshold", self.LUA)


if __name__ == "__main__":
    unittest.main()
