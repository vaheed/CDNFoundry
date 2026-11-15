"""A client must be counted once per IPv6 prefix, not once per address, and under one key however its address is written.

The key module (docker/openresty/client_key.lua) runs in plain Lua with a small address parser standing in for the runtime's
inet_pton. The same behavior on a real cell, through Nginx's own limits and the Lua rate limiter, is qualified in
tests/e2e/ipv6_rate_limit_keys.py.
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
local keys = require "client_key"

-- inet_pton for the tests: IPv4 dotted quads and IPv6 with "::" and an optional dotted IPv4 tail.
local function ipv4(text)
  local a, b, c, d = text:match("^(%d+)%.(%d+)%.(%d+)%.(%d+)$")
  if not a then return nil end
  a, b, c, d = tonumber(a), tonumber(b), tonumber(c), tonumber(d)
  if a > 255 or b > 255 or c > 255 or d > 255 then return nil end
  return string.char(a, b, c, d)
end
local function groups(text)
  local out = {{}}
  if text == "" then return out end
  for part in (text .. ":"):gmatch("([^:]*):") do out[#out + 1] = part end
  return out
end
function pack(text)
  if type(text) ~= "string" then return nil end
  if not text:find(":", 1, true) then return ipv4(text) end
  local head, tail = text, nil
  local split = text:find("::", 1, true)
  if split then head, tail = text:sub(1, split - 1), text:sub(split + 2) end
  local function words(part)
    local list = {{}}
    for _, group in ipairs(groups(part)) do
      local v4 = group:find(".", 1, true) and ipv4(group)
      if v4 then list[#list + 1] = v4:byte(1) * 256 + v4:byte(2); list[#list + 1] = v4:byte(3) * 256 + v4:byte(4)
      else
        if not group:match("^%x%x?%x?%x?$") then return nil end
        list[#list + 1] = tonumber(group, 16)
      end
    end
    return list
  end
  local first = words(head); if not first then return nil end
  local all = first
  if tail ~= nil then
    local second = words(tail); if not second then return nil end
    local gap = 8 - #first - #second
    if gap < 1 then return nil end
    all = {{}}
    for _, w in ipairs(first) do all[#all + 1] = w end
    for _ = 1, gap do all[#all + 1] = 0 end
    for _, w in ipairs(second) do all[#all + 1] = w end
  end
  if #all ~= 8 then return nil end
  local out = {{}}
  for _, w in ipairs(all) do out[#out + 1] = string.char(math.floor(w / 256), w % 256) end
  return table.concat(out)
end
function hex(value) return (value:gsub(".", function(c) return string.format("%02x", c:byte()) end)) end
function make(prefix, exceptions)
  local compiled = keys.exceptions(exceptions or "", pack)
  return keys.new({{pack = pack, prefix = prefix, exceptions = compiled}})
end
"""


def interpreter() -> str | None:
    return next((shutil.which(name) for name in ("luajit", "lua5.1", "lua", "lua5.4") if shutil.which(name)), None)


@unittest.skipIf(interpreter() is None, "no Lua interpreter available")
class ClientKey(unittest.TestCase):
    def lua(self, body: str) -> list[str]:
        with tempfile.TemporaryDirectory() as directory:
            script = pathlib.Path(directory) / "run.lua"
            script.write_text(PRELUDE + body)
            result = subprocess.run([interpreter(), str(script)], capture_output=True, text=True, cwd=directory)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result.stdout.split()

    def test_every_address_of_a_64_shares_one_key_and_another_64_has_another(self):
        out = self.lua("""
local key = make(64)
local a = hex(key("2001:db8:1:2::1"))
local b = hex(key("2001:db8:1:2:aaaa:bbbb:cccc:dddd"))
local c = hex(key("2001:db8:1:2:ffff:ffff:ffff:ffff"))
local other = hex(key("2001:db8:1:3::1"))
print(a == b, b == c, a == other, a)
""")
        self.assertEqual(out[:3], ["true", "true", "false"])
        self.assertEqual(out[3], "20010db800010002" + "0" * 16)

    def test_one_address_written_two_ways_is_one_client(self):
        out = self.lua("""
local key = make(64)
print(key("2001:db8::1") == key("2001:0db8:0000:0000:0000:0000:0000:0001"), key("2001:DB8::1") == key("2001:db8::1"))
print(key("2001:db8::1") == key("2001:db8::2"))
""")
        self.assertEqual(out, ["true", "true", "true"])

    def test_ipv4_is_counted_per_address_and_ipv4_mapped_ipv6_is_the_same_client(self):
        out = self.lua("""
local key = make(64)
print(hex(key("203.0.113.7")), key("203.0.113.7") == key("::ffff:203.0.113.7"), key("203.0.113.7") == key("203.0.113.8"))
print(#key("203.0.113.7"), #key("2001:db8::1"))
""")
        self.assertEqual(out, ["cb007107", "true", "false", "4", "16"])

    def test_the_prefix_is_configurable_and_handles_lengths_that_are_not_whole_bytes(self):
        out = self.lua("""
local k56, k48, k60, k128 = make(56), make(48), make(60), make(128)
print(k56("2001:db8:1:2::1") == k56("2001:db8:1:2:9::1"), k56("2001:db8:1:2::1") == k56("2001:db8:1:3::1"), k56("2001:db8:1:2::1") == k56("2001:db8:1:100::1"))
print(k48("2001:db8:1:2::1") == k48("2001:db8:1:ffff::1"), k48("2001:db8:1::1") == k48("2001:db8:2::1"))
print(k60("2001:db8:1:20::1") == k60("2001:db8:1:2f::1"), k60("2001:db8:1:20::1") == k60("2001:db8:1:30::1"))
print(k128("2001:db8::1") == k128("2001:db8::2"), k128("2001:db8::1") == k128("2001:db8::1"))
""")
        self.assertEqual(out, ["true", "true", "false", "true", "false", "true", "false", "false", "true"])

    def test_listed_networks_keep_the_full_address_and_others_are_grouped(self):
        out = self.lua("""
local key = make(64, "2001:db8:aaaa::/48, 2001:db8:bbbb:1::5")
print(key("2001:db8:aaaa:1::1") == key("2001:db8:aaaa:1::2"), key("2001:db8:aaaa:1::1") == key("2001:db8:aaaa:2::1"))
print(key("2001:db8:bbbb:1::5") == key("2001:db8:bbbb:1::6"), key("2001:db8:bbbb:1::6") == key("2001:db8:bbbb:1::7"))
print(key("2001:db8:cccc:1::1") == key("2001:db8:cccc:1::2"))
""")
        self.assertEqual(out, ["false", "false", "false", "true", "true"])

    def test_an_address_that_does_not_parse_is_counted_as_itself(self):
        out = self.lua("""
local key = make(64)
print(key("not-an-address"), key("not-an-address") == key("not-an-address"), key("") == "", key(nil) == nil)
""")
        self.assertEqual(out, ["not-an-address", "true", "true", "true"])

    def test_the_prefix_setting_falls_back_to_64_unless_it_is_a_whole_number_from_32_to_128(self):
        out = self.lua("""
for _, value in ipairs({"64", "56", "48", "32", "128", "31", "129", "abc", "6.5", ""}) do
  local bits, refused = keys.prefix(value)
  io.write(value, "=", bits, ":", tostring(refused), " ")
end
local bits, refused = keys.prefix(nil)
io.write("nil=", bits, ":", tostring(refused))
""")
        self.assertEqual(out, ["64=64:false", "56=56:false", "48=48:false", "32=32:false", "128=128:false", "31=64:true",
                               "129=64:true", "abc=64:true", "6.5=64:true", "=64:false", "nil=64:false"])

    def test_exceptions_refuse_ipv4_garbage_and_more_than_the_limit(self):
        out = self.lua("""
local networks, refused = keys.exceptions("2001:db8::/32, 203.0.113.0/24, nonsense, 2001:db8::1/129", pack)
print(#networks, #refused)
local many = {}
for i = 1, 80 do many[#many + 1] = string.format("2001:db8:%x::/48", i) end
networks, refused = keys.exceptions(table.concat(many, ","), pack)
print(#networks, #refused)
""")
        self.assertEqual(out, ["1", "3", "64", "16"])


class RuntimeUsesClientKeys(unittest.TestCase):
    LUA = (MODULE / "runtime.lua").read_text()
    NGINX = (ROOT / "docker/nginx/edge-runtime.conf").read_text()

    def test_every_per_client_counter_uses_the_key_not_the_address(self):
        self.assertIn('ngx.md5(client_counting_key) .. ":" .. second', self.LUA)
        self.assertIn('"security:conn:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client_counting_key)', self.LUA)
        self.assertIn('ngx.md5(client_limit_key(client)) .. ":"', self.LUA)
        self.assertIn('if limit.key == "client_ip" then return request.client_key end', self.LUA)
        self.assertNotIn("ngx.md5(client) ..", self.LUA)

    def test_nginx_limits_count_under_the_key_not_the_binary_address(self):
        self.assertNotIn("$binary_remote_addr", self.NGINX)
        self.assertIn("limit_conn_zone $cdn_client_limit_key", self.NGINX)
        self.assertIn("limit_req_zone $cdn_client_limit_key", self.NGINX)
        self.assertIn("set_by_lua_block $cdn_client_limit_key", self.NGINX)

    def test_the_settings_reach_the_workers(self):
        conf = (ROOT / "docker/nginx/openresty.conf").read_text()
        self.assertIn("env EDGE_IPV6_RATE_PREFIX;", conf)
        self.assertIn("env EDGE_IPV6_EXACT_CIDRS;", conf)


if __name__ == "__main__":
    unittest.main()
