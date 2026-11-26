#!/usr/bin/env python3
"""Measure named IP lists on a real OpenResty before anything depends on them.

Runs the cell's own `ip_list.lua` under the image's LuaJIT and cjson inside the edge-runtime image with lists at the maximum size (10,000 ranges), for IPv4,
IPv6 and a mix. Reports the snapshot bytes a list adds, the time to decode and validate it, the memory one decoded copy holds,
and the lookup time per request (p50, p99, max), and fails when p99 is not under 20 microseconds. Every worker holds its own
copy of the decoded lists, so the memory line is per worker.
"""
import json
import os
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = os.environ.get("CDNF_WAF_IMAGE", "cdnfoundry/edge-runtime:fairness-qualification")
LIMIT_MICROSECONDS = 20.0

SCRIPT = r'''
package.path = "/work/?.lua;" .. package.path
local cjson = require "cjson.safe"
local ffi = require "ffi"
local ip_list = require "ip_list"
ffi.cdef[[ typedef long time_t; struct timespec { time_t tv_sec; long tv_nsec; }; int clock_gettime(int clk, struct timespec *ts); ]]
local ts = ffi.new("struct timespec")
local function now() ffi.C.clock_gettime(1, ts); return tonumber(ts.tv_sec) * 1e9 + tonumber(ts.tv_nsec) end

math.randomseed(7)
local N = 10000
-- A list as the control plane compiles it: random networks, merged, sorted.
local function build_v4(count)
    local starts = {}
    for _ = 1, count do starts[#starts + 1] = math.random(0, 4294967295 - 4096) end
    table.sort(starts)
    local ranges, last = {}, -1
    for _, first in ipairs(starts) do
        if first > last then local finish = first + math.random(0, 255); ranges[#ranges + 1] = first; ranges[#ranges + 1] = finish; last = finish end
    end
    return ranges
end
local function words(high, low) return {math.floor(high / 4294967296), high % 4294967296, math.floor(low / 4294967296), low % 4294967296} end
local function build_v6(count)
    local starts = {}
    for _ = 1, count do starts[#starts + 1] = math.random(0, 2^40) * 4096 end
    table.sort(starts)
    local ranges, last = {}, -1
    for _, first in ipairs(starts) do
        if first > last then
            local finish = first + 4095
            for _, word in ipairs(words(first, 0)) do ranges[#ranges + 1] = word end
            local w = words(finish, 0)
            ranges[#ranges + 1] = w[1]; ranges[#ranges + 1] = w[2]; ranges[#ranges + 1] = 4294967295; ranges[#ranges + 1] = 4294967295
            last = finish
        end
    end
    return ranges
end
local function address6(first, low)
    local w = words(first, low)
    local out = {}
    for _, value in ipairs(w) do out[#out + 1] = string.char(math.floor(value / 16777216) % 256, math.floor(value / 65536) % 256, math.floor(value / 256) % 256, value % 256) end
    return table.concat(out)
end
local function pack4(value) return string.char(math.floor(value / 16777216) % 256, math.floor(value / 65536) % 256, math.floor(value / 256) % 256, value % 256) end
local function pack6(ranges, index) return address6(ranges[index] * 4294967296 + ranges[index + 1], ranges[index + 2] * 4294967296 + ranges[index + 3]) end

local function measure(label, list, probes)
    local json = cjson.encode(list)
    collectgarbage(); collectgarbage()
    local before = collectgarbage("count")
    local started = now()
    local decoded = cjson.decode(json)
    assert(ip_list.valid(decoded), "the list must validate")
    local decode_us = (now() - started) / 1e3
    collectgarbage(); collectgarbage()
    local memory = (collectgarbage("count") - before) * 1024
    -- Timer overhead alone, subtracted from every sample.
    local overhead = {}
    for i = 1, 20000 do local a = now(); overhead[i] = now() - a end
    table.sort(overhead)
    local base = overhead[math.floor(#overhead / 2)]
    local samples, hits = {}, 0
    for i = 1, #probes do
        local packed = probes[i]
        local a = now()
        local found = ip_list.contains(decoded, packed)
        local b = now()
        if found then hits = hits + 1 end
        samples[i] = math.max(0, b - a - base)
    end
    table.sort(samples)
    local function at(fraction) return samples[math.max(1, math.ceil(#samples * fraction))] / 1e3 end
    return {label = label, json_bytes = #json, decode_validate_us = decode_us, memory_bytes_per_worker = memory,
        ranges = #(decoded.v4 or {}) / 2 + #(decoded.v6 or {}) / 8, lookups = #probes, hits = hits,
        p50_us = at(0.5), p99_us = at(0.99), p999_us = at(0.999), max_us = samples[#samples] / 1e3}
end

local v4, v6 = build_v4(N), build_v6(N)
local half4, half6 = build_v4(N / 2), build_v6(N / 2)
local probes4, probes6, mixed = {}, {}, {}
for i = 1, 200000 do
    local range = math.random(1, #v4 / 2)
    probes4[i] = pack4(i % 2 == 0 and v4[range * 2 - 1] + math.random(0, 3) or math.random(0, 4294967295))
    local r6 = math.random(1, #v6 / 8)
    probes6[i] = i % 2 == 0 and pack6(v6, (r6 - 1) * 8 + 1) or address6(math.random(0, 2^40) * 4096 + 1, 5)
    mixed[i] = i % 2 == 0 and pack4(half4[math.random(1, #half4 / 2) * 2 - 1]) or pack6(half6, (math.random(1, #half6 / 8) - 1) * 8 + 1)
end
local results = {
    measure("ipv4 10000 ranges", {v4 = v4}, probes4),
    measure("ipv6 10000 ranges", {v6 = v6}, probes6),
    measure("ipv4 and ipv6 together, 10000 ranges", {v4 = half4, v6 = half6}, mixed),
}
-- Refusals: unsorted, overlapping, odd length, wrong width, over the maximum.
local refused = {
    ip_list.valid({v4 = {5, 9, 1, 3}}), ip_list.valid({v4 = {1, 9, 5, 12}}), ip_list.valid({v4 = {1, 2, 3}}),
    ip_list.valid({v6 = {0, 0, 0, 1, 0, 0, 0, 0}}), ip_list.valid({v4 = (function() local r = {} for i = 1, 10001 do r[#r + 1] = i * 4; r[#r + 1] = i * 4 + 1 end return r end)()}), ip_list.valid({v4 = {-1, 4}}),
}
ngx = nil
print(cjson.encode({results = results, refused = refused}))
'''


def main() -> None:
    scratch = pathlib.Path(os.environ.get("TMPDIR", "/tmp")) / "ip-list-feasibility"
    scratch.mkdir(exist_ok=True)
    (scratch / "ip_list.lua").write_text((ROOT / "docker/openresty/ip_list.lua").read_text())
    (scratch / "bench.lua").write_text(SCRIPT)
    scratch.chmod(0o755)
    for file in scratch.iterdir():
        file.chmod(0o644)
    result = subprocess.run(["docker", "run", "--rm", "--entrypoint", "/usr/local/openresty/luajit/bin/luajit", "-e", "LUA_CPATH=/usr/local/openresty/lualib/?.so;;", "-v", f"{scratch}:/work:ro", IMAGE, "/work/bench.lua"], capture_output=True, text=True, timeout=600)
    if result.returncode != 0:
        sys.exit(result.stderr + result.stdout)
    report = json.loads(result.stdout.strip().splitlines()[-1])
    for row in report["results"]:
        print(json.dumps({key: (round(value, 2) if isinstance(value, float) else value) for key, value in row.items()}))
        assert row["p99_us"] < LIMIT_MICROSECONDS, f"{row['label']}: p99 {row['p99_us']:.2f} us is over {LIMIT_MICROSECONDS} us"
    assert report["refused"] == [False] * 6, report["refused"]
    print("ip list feasibility passed: p99 under", LIMIT_MICROSECONDS, "us for every list, malformed lists refused")


if __name__ == "__main__":
    main()
