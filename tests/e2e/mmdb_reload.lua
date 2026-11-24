-- Real libmaxminddb handles: first-start fallback, live replacement, invalid replacement and retry.
package.path = "/module/?.lua;" .. package.path
ngx = {WARN = 1, log = function() end}
local geoip = require "geoip"
local function copy(source, destination)
    local input = assert(io.open(source, "rb"))
    local content = input:read("*a"); input:close()
    local output = assert(io.open(destination .. ".next", "wb"))
    output:write(content); output:close()
    assert(os.rename(destination .. ".next", destination))
end
local function stamp(value)
    local file = assert(io.open("/tmp/asn.mmdb.revision", "w"))
    file:write(value); file:close()
end
copy("/data/fallback.mmdb", "/tmp/asn.mmdb")
geoip.refresh()
assert(geoip.autonomous_system("1.1.1.1") == 0)
copy("/data/GeoLite2-ASN.mmdb", "/tmp/asn.mmdb")
stamp("valid")
geoip.refresh()
assert(geoip.autonomous_system("1.1.1.1") == 13335)
assert(geoip.autonomous_system("2606:4700:4700::1111") == 13335)
local file = assert(io.open("/tmp/asn.mmdb.next", "w")); file:write("broken"); file:close()
assert(os.rename("/tmp/asn.mmdb.next", "/tmp/asn.mmdb"))
stamp("invalid")
geoip.refresh()
assert(geoip.autonomous_system("1.1.1.1") == 13335)
-- A failed open must not advance the stamp: retry the same revision after recovery.
copy("/data/fallback.mmdb", "/tmp/asn.mmdb")
geoip.refresh()
assert(geoip.autonomous_system("1.1.1.1") == 0)
print("PASS: live IPv4/IPv6 MMDB reload, last-valid handle retention and retry")
