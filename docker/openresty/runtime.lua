local cjson = require "cjson.safe"
local resolver = require "resty.dns.resolver"
local balancer = require "ngx.balancer"
local bit = require "bit"
local cache_target = require "cache_key"
local ffi = require "ffi"
ffi.cdef[[
int kill(int pid, int sig);
int inet_pton(int af, const char *src, void *dst);
]]
local resty_sha256 = require "resty.sha256"
local resty_string = require "resty.string"
local M = {}
local telemetry_proof
local state = { hosts = {}, certificates = {}, sequence = 0, generation_id = "bootstrap" }
local path = os.getenv("EDGE_RUNTIME_FILE") or "/var/lib/cdnfoundry/runtime/active.json"
local restart_generation = 0
local certificate_cache = {}
local certificate_cache_order = {}
local certificate_cache_limit = 512
local cache_control_directives
local geo_database
local asn_database

-- Proves to the local Vector that an access-log line came from this cell. Other
-- containers on the cell network do not hold the node secret, so Vector drops
-- any event they forge.
local function telemetry_proof_value()
    if telemetry_proof == nil then
        local digest = resty_sha256:new()
        digest:update("cdnf-telemetry-v1|" .. (os.getenv("EDGE_STATUS_TOKEN") or ""))
        telemetry_proof = resty_string.to_hex(digest:final())
    end
    return telemetry_proof
end

-- Diagnostic headers (X-CDNF-* and Server) are shown only when the platform
-- setting is on. Snapshots that predate the setting keep showing them.
local function diagnostic_headers_enabled()
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    return not (config and config.settings and config.settings.diagnostic_headers == false)
end

-- The control plane sends the state it sets from edge reports as a small overlay per domain, apart from the signed
-- snapshot. A cell enforces the stricter value of snapshot and overlay for every limit.
local overlay_cache = {}
local function effective_limits(config, security)
    local limits = security.limits or {}
    local raw = ngx.shared.runtime_limits:get("overlay:" .. tostring(config.domain_id or config.domain))
    if not raw then return limits end
    local cached = overlay_cache[raw]
    if cached and cached.base == limits then return cached.merged end
    local overlay = cjson.decode(raw)
    if type(overlay) ~= "table" or type(overlay.limits) ~= "table" then return limits end
    local merged = {}
    for name, value in pairs(limits) do merged[name] = value end
    for name, value in pairs(overlay.limits) do
        local current = tonumber(merged[name])
        if tonumber(value) and (current == nil or tonumber(value) < current) then merged[name] = value end
    end
    local count = 0
    for _ in pairs(overlay_cache) do count = count + 1 end
    if count >= 256 then overlay_cache = {} end
    overlay_cache[raw] = { base = limits, merged = merged }
    return merged
end

local function security_reject(status, reason)
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("capacity:rejected_requests", 1, 0)
    dictionary:incr("security:reason:" .. reason, 1, 0)
    if ngx.ctx.security_domain then
        local events = ngx.shared.security_limits
        local key = "event:" .. tostring(ngx.ctx.security_domain) .. ":" .. reason
        -- Every key of one event expires together, so a reason that stops occurring
        -- leaves the next heartbeat instead of repeating forever.
        events:incr(key, 1, 0, 3600)
        events:set("host:" .. tostring(ngx.ctx.security_domain), ngx.ctx.security_hostname or "", 3600)
        events:set("time:" .. tostring(ngx.ctx.security_domain) .. ":" .. reason, ngx.time(), 3600)
    end
    if diagnostic_headers_enabled() then ngx.header["X-CDNF-Security-Reason"] = reason else ngx.header["Server"] = nil end
    ngx.var.cdn_security_reason = reason
    ngx.var.cdn_security_action = "block"
    local body = ngx.ctx.reject_body
    if body then
        -- A rejection that explains itself, such as the incident challenge page: the status is the rejection's own.
        ngx.status = status
        ngx.header["Content-Type"] = "text/html; charset=utf-8"
        ngx.header["Cache-Control"] = "no-store"
        ngx.header["Content-Security-Policy"] = ngx.ctx.reject_csp or "default-src 'none'"
        ngx.print(body)
        return ngx.exit(ngx.HTTP_OK)
    end
    return ngx.exit(status)
end


-- The incident profile rides in the domain's overlay. The cell expires it by its own clock, so it ends even if the control plane or
-- the agent is unreachable. Nothing here calls out: the challenge is stateless apart from a bounded single-use seed set.
local incident_cache = {}
local function incident_for(config)
    local raw = ngx.shared.runtime_limits:get("overlay:" .. tostring(config.domain_id or config.domain))
    if not raw then return nil end
    local cached = incident_cache[raw]
    if cached == nil then
        local overlay = cjson.decode(raw)
        cached = (type(overlay) == "table" and type(overlay.incident) == "table") and overlay.incident or false
        local count = 0
        for _ in pairs(incident_cache) do count = count + 1 end
        if count >= 256 then incident_cache = {} end
        incident_cache[raw] = cached
    end
    if cached and type(cached.expires_at) == "number" and cached.expires_at > ngx.time() then return cached end
    return nil
end

local CHALLENGE_PATH = "/.cdnf/challenge"
local CHALLENGE_BITS = 16
local CHALLENGE_SEED_SECONDS = 300
local CHALLENGE_ISSUES_PER_MINUTE = 30
local CHALLENGE_TOKEN_SECONDS = 3600

local challenge_key
local function sha256_raw(data)
    local digest = resty_sha256:new()
    digest:update(data)
    return digest:final()
end

-- HMAC-SHA256 with a per-cell key derived from the cell's own secret, so a token or seed from one cell means nothing on another.
local function hmac_sha256_hex(message)
    if not challenge_key then
        challenge_key = sha256_raw("cdnf-challenge-v1|" .. (os.getenv("EDGE_STATUS_TOKEN") or "") .. "|" .. (os.getenv("EDGE_CELL_NAME") or ""))
    end
    local key = challenge_key .. string.rep("\0", 64 - #challenge_key)
    local ipad, opad = {}, {}
    for i = 1, 64 do
        local byte = key:byte(i)
        ipad[i] = string.char(bit.bxor(byte, 0x36))
        opad[i] = string.char(bit.bxor(byte, 0x5c))
    end
    return resty_string.to_hex(sha256_raw(table.concat(opad) .. sha256_raw(table.concat(ipad) .. message)))
end

local function constant_time_equal(a, b)
    if type(a) ~= "string" or type(b) ~= "string" or #a ~= #b then return false end
    local difference = 0
    for i = 1, #a do difference = bit.bor(difference, bit.bxor(a:byte(i), b:byte(i))) end
    return difference == 0
end

-- A token and a seed are bound to one domain, one client address, and one user agent.
local function client_binding(client)
    return resty_string.to_hex(sha256_raw(tostring(client) .. "|" .. (ngx.var.http_user_agent or ""))):sub(1, 16)
end

local function valid_challenge_token(config, client)
    local token = ngx.var.cookie_cdnf_chal
    if type(token) ~= "string" or #token > 200 then return false end
    local domain, expires, binding, mac = token:match("^(%d+)%.(%d+)%.(%x+)%.(%x+)$")
    if not domain or tonumber(domain) ~= tonumber(config.domain_id or 0) or tonumber(expires) <= ngx.time() then return false end
    if binding ~= client_binding(client) then return false end
    return constant_time_equal(mac, hmac_sha256_hex("token|" .. domain .. "|" .. expires .. "|" .. binding))
end

local function leading_zero_bits(raw)
    local bits = 0
    for i = 1, #raw do
        local byte = raw:byte(i)
        if byte == 0 then bits = bits + 8 else
            while byte < 128 do bits = bits + 1; byte = byte * 2 end
            break
        end
    end
    return bits
end

local function challenge_page(seed, nonce)
    return table.concat({
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">',
        '<title>Checking your browser</title><meta name="robots" content="noindex">',
        '<style nonce="', nonce, '">body{margin:0;font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;background:#fff;color:#1a1a1a}',
        'main{max-width:30rem;padding:1.5rem}h1{font-size:1.25rem;margin:0 0 .5rem}p{margin:.5rem 0}',
        '@media (prefers-color-scheme:dark){body{background:#141414;color:#ececec}}</style></head><body><main>',
        '<h1>Checking your browser</h1>',
        '<p id="status" role="status" aria-live="polite">This takes a few seconds. The page continues on its own.</p>',
        '<noscript><p>This site is handling a flood of requests and needs JavaScript to confirm that a person is visiting. ',
        'Turn JavaScript on and reload, or contact the site owner.</p></noscript>',
        '<script nonce="', nonce, '">(async()=>{const seed="', seed, '",bits=', tostring(CHALLENGE_BITS), ',status=document.getElementById("status");',
        'try{const enc=new TextEncoder();const lead=h=>{let n=0;for(const b of h){if(b===0){n+=8;continue}n+=Math.clz32(b)-24;break}return n};',
        'let c=0;for(;;c++){const h=new Uint8Array(await crypto.subtle.digest("SHA-256",enc.encode(seed+":"+c)));if(lead(h)>=bits)break}',
        'const r=await fetch("', CHALLENGE_PATH, '",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},credentials:"same-origin",body:"seed="+encodeURIComponent(seed)+"&counter="+c});',
        'if(r.ok){location.reload()}else{status.textContent="Verification failed. Reload the page to try again."}}',
        'catch(e){status.textContent="This browser cannot complete the check. Try a current browser over HTTPS."}})();</script></main></body></html>',
    })
end

-- Returns nil when the request may continue; otherwise it has answered and the caller returns the result.
local function enforce_challenge(config, incident, client, trusted)
    local method = ngx.req.get_method()
    local uri = ngx.var.uri or "/"
    if uri == CHALLENGE_PATH then
        if method ~= "POST" then return security_reject(405, "invalid_method") end
        ngx.req.read_body()
        local raw = ngx.req.get_body_data() or ""
        if #raw > 512 then return security_reject(413, "body_too_large") end
        local seed, counter = raw:match("^seed=([%w%%%.%-_]+)&counter=(%d+)$")
        seed = seed and ngx.unescape_uri(seed)
        local domain, issued, bits, mac = (seed or ""):match("^(%d+)%.(%d+)%.(%d+)%.(%x+)$")
        local valid = domain and tonumber(domain) == tonumber(config.domain_id or 0) and tonumber(issued) + CHALLENGE_SEED_SECONDS >= ngx.time()
            and tonumber(issued) <= ngx.time() + 5 and tonumber(bits) == CHALLENGE_BITS and #counter <= 12
            and constant_time_equal(mac, hmac_sha256_hex("seed|" .. domain .. "|" .. issued .. "|" .. bits .. "|" .. client_binding(client)))
            and leading_zero_bits(sha256_raw(seed .. ":" .. counter)) >= CHALLENGE_BITS
        if not valid then return security_reject(403, "challenge_invalid") end
        -- A seed works once: replaying a solved seed, or a seed from another client, is refused.
        local added = ngx.shared.runtime_limits:add("challenge:used:" .. ngx.md5(seed), true, CHALLENGE_SEED_SECONDS)
        if not added then return security_reject(403, "challenge_invalid") end
        local expires = math.min(tonumber(incident.expires_at), ngx.time() + CHALLENGE_TOKEN_SECONDS)
        local binding = client_binding(client)
        local token = tostring(config.domain_id or 0) .. "." .. tostring(expires) .. "." .. binding .. "." .. hmac_sha256_hex("token|" .. tostring(config.domain_id or 0) .. "|" .. tostring(expires) .. "|" .. binding)
        ngx.header["Set-Cookie"] = "cdnf_chal=" .. token .. "; Path=/; Max-Age=" .. tostring(expires - ngx.time()) .. "; HttpOnly; SameSite=Lax" .. (ngx.var.https == "on" and "; Secure" or "")
        ngx.header["Cache-Control"] = "no-store"
        ngx.status = 204
        return ngx.exit(ngx.HTTP_NO_CONTENT)
    end
    if trusted or valid_challenge_token(config, client) then return nil end
    for _, prefix in ipairs(incident.challenge_bypass_prefixes or {}) do
        if type(prefix) == "string" and prefix ~= "" and uri:sub(1, #prefix) == prefix then return nil end
    end
    -- Only a person's page load gets the challenge; every other request without a token is refused, so a script never reaches the origin.
    local accepts_html = (method == "GET" or method == "HEAD") and (ngx.var.http_accept or ""):find("text/html", 1, true)
    if not accepts_html then return security_reject(403, "challenge_required") end
    local issues = ngx.shared.runtime_limits:incr("challenge:issue:" .. ngx.md5(client) .. ":" .. math.floor(ngx.time() / 60), 1, 0, 120)
    if not issues or issues > CHALLENGE_ISSUES_PER_MINUTE then return security_reject(429, "client_rate_exceeded") end
    local issued = tostring(ngx.time())
    local binding = client_binding(client)
    local seed = tostring(config.domain_id or 0) .. "." .. issued .. "." .. tostring(CHALLENGE_BITS) .. "." .. hmac_sha256_hex("seed|" .. tostring(config.domain_id or 0) .. "|" .. issued .. "|" .. tostring(CHALLENGE_BITS) .. "|" .. binding)
    local nonce = ngx.var.request_id or ngx.md5(seed)
    ngx.ctx.reject_body = challenge_page(seed, nonce)
    ngx.ctx.reject_csp = "default-src 'none'; script-src 'nonce-" .. nonce .. "'; style-src 'nonce-" .. nonce .. "'; connect-src 'self'; base-uri 'none'; form-action 'none'"
    return security_reject(403, "challenge_required")
end

local function ipv4_number(ip)
    local a,b,c,d = ip:match("^(%d+)%.(%d+)%.(%d+)%.(%d+)$")
    if not a then return nil end
    return tonumber(a)*16777216 + tonumber(b)*65536 + tonumber(c)*256 + tonumber(d)
end

-- Runtime images are Linux. Parse address values with libc instead of testing
-- textual IPv6 prefixes; expanded, mixed and compressed forms must agree.
local function packed_address(address)
    if type(address) ~= "string" or #address > 45 or address:find("%z") then return nil end
    local ipv6 = address:find(":", 1, true) ~= nil
    local bytes = ffi.new("unsigned char[16]")
    if ffi.C.inet_pton(ipv6 and 10 or 2, address, bytes) ~= 1 then return nil end
    return ffi.string(bytes, ipv6 and 16 or 4)
end

local function network_parts(cidr)
    if type(cidr) ~= "string" then return nil end
    local address, bits = cidr:match("^([^/]+)/(%d+)$")
    local packed = packed_address(address or cidr)
    if not packed then return nil end
    bits = bits and tonumber(bits) or #packed * 8
    if bits < 0 or bits > #packed * 8 then return nil end
    return packed, bits
end

local function matches_prefix(address, network, bits)
    if not address or not network or #address ~= #network then return false end
    local whole, remainder = math.floor(bits / 8), bits % 8
    if address:sub(1, whole) ~= network:sub(1, whole) then return false end
    if remainder == 0 then return true end
    local mask = bit.band(0xff, bit.lshift(0xff, 8 - remainder))
    return bit.band(address:byte(whole + 1), mask) == bit.band(network:byte(whole + 1), mask)
end

local function allowed(ip, networks)
    local address = packed_address(ip)
    if not address then return false end
    for _, cidr in ipairs(networks or {}) do
        local network, bits = network_parts(cidr)
        if network and matches_prefix(address, network, bits) then return true end
    end
    return false
end

local function compile_networks(networks)
    local compiled = {}
    for _, cidr in ipairs(networks) do
        local address, bits = network_parts(cidr)
        compiled[#compiled + 1] = {address, bits}
    end
    return compiled
end

local hard_origin_networks = compile_networks({
    "0.0.0.0/8", "127.0.0.0/8", "169.254.0.0/16", "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24",
    "198.18.0.0/15", "198.51.100.0/24", "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4",
    "::/128", "::1/128", "::ffff:0:0/96", "64:ff9b::/96", "64:ff9b:1::/48",
    "fe80::/10", "fec0::/10", "ff00::/8", "2001:db8::/32",
    -- Transition ranges embed an IPv4 address that would bypass the IPv4 rules.
    "::/96", "2002::/16", "2001::/23", "100::/64",
})
local private_origin_networks = compile_networks({
    "10.0.0.0/8", "100.64.0.0/10", "172.16.0.0/12", "192.168.0.0/16", "fc00::/7",
})

-- The only ports an origin may use. Mirrors OriginData::ALLOWED_PORTS in the control plane.
local allowed_origin_ports = {}
for _, port in ipairs({80, 443, 2052, 2053, 2082, 2083, 2086, 2087, 2095, 2096, 8000, 8008, 8080, 8081, 8088, 8443, 8444, 8880, 8888, 9000, 9443}) do allowed_origin_ports[port] = true end

local function blocked(ip, networks, blocked_networks, denied)
    local packed = packed_address(ip)
    if not packed then return true end
    for _, address in ipairs(denied or {}) do
        if packed == packed_address(address) then return true end
    end
    if allowed(ip, blocked_networks) then return true end
    for _, network in ipairs(hard_origin_networks) do
        if matches_prefix(packed, network[1], network[2]) then return true end
    end
    for _, network in ipairs(private_origin_networks) do
        if matches_prefix(packed, network[1], network[2]) then return not allowed(ip, networks) end
    end
    return false
end

local function load()
    local file = io.open(path, "rb")
    if not file then return nil, "runtime state unavailable" end
    local raw = file:read("*a"); file:close()
    if #raw > 64 * 1024 * 1024 then return nil, "runtime state exceeds limit" end
    local decoded = cjson.decode(raw)
    if not decoded or decoded.schema_version ~= 1 or type(decoded.hosts) ~= "table"
        or type(decoded.generation_id) ~= "string" or #decoded.generation_id > 64 then
        return nil, "invalid runtime state"
    end
    if decoded.certificates == nil then decoded.certificates = {} end
    if type(decoded.certificates) ~= "table" then return nil, "invalid runtime certificates" end
    if decoded.sequence ~= state.sequence then
        certificate_cache = {}
        certificate_cache_order = {}
    end
    state = decoded
    return true
end

-- The agent writes a stamp (the SHA-256 of the runtime file) beside every runtime file, after the
-- file itself. Polling that few-byte stamp costs almost nothing; the state, which can be tens of
-- megabytes, is decoded only when the stamp changes. Without a stamp the file is loaded every tick.
local stamp_path = path:gsub("%.json$", ".stamp")
local loaded_stamp

local function read_stamp()
    local file = io.open(stamp_path, "rb")
    if not file then return nil end
    local stamp = file:read(64)
    file:close()
    return stamp
end

local function refresh(premature)
    if premature then return end
    local stamp = read_stamp()
    if stamp ~= nil and stamp == loaded_stamp then return end
    local ok, err = load()
    if not ok then
        ngx.log(ngx.WARN, err)
        return
    end
    loaded_stamp = stamp
    ngx.log(ngx.NOTICE, "runtime state loaded generation=", state.generation_id, " sequence=", tostring(state.sequence))
end

function M.start()
    refresh(false)
    local geo_path = os.getenv("GEOIP_DATABASE") or ""
    if geo_path ~= "" then
        local ok_module, maxminddb = pcall(require, "maxminddb")
        if ok_module then
            local ok_open, database = pcall(maxminddb.open, geo_path)
            if ok_open then geo_database = database else ngx.log(ngx.WARN, "GeoIP database unavailable: ", database) end
        else
            ngx.log(ngx.WARN, "GeoIP module unavailable: ", maxminddb)
        end
    end
    local asn_path = os.getenv("GEOIP_ASN_DATABASE") or ""
    if asn_path ~= "" then
        local ok_module, maxminddb = pcall(require, "maxminddb")
        if ok_module then
            local ok_open, database = pcall(maxminddb.open, asn_path)
            if ok_open then asn_database = database else ngx.log(ngx.WARN, "GeoIP ASN database unavailable: ", database) end
        end
    end
    local ok, err = ngx.timer.every(1, refresh)
    if not ok then ngx.log(ngx.ERR, "runtime refresh timer failed: ", err) end
    restart_generation = ngx.shared.runtime_limits:get("control:restart_generation") or 0
    ok, err = ngx.timer.every(0.5, function(premature)
        if premature then return end
        local dictionary = ngx.shared.runtime_limits
        local resume_at = dictionary:get("control:restart_resume_at")
        if resume_at and ngx.now() >= resume_at then
            dictionary:delete("control:restart_resume_at")
            dictionary:delete("control:drained")
        end
        local current = dictionary:get("control:restart_generation") or 0
        if current > restart_generation then
            restart_generation = current
            ffi.C.kill(ngx.worker.pid(), 15)
        end
    end)
    if not ok then ngx.log(ngx.ERR, "runtime restart timer failed: ", err) end
end

-- The domain-wide handshake ceiling is this many times the per-client budget. The per-client
-- budget itself is enforced by the gateway, which sees the real client address; during the TLS
-- handshake a cell sees only the gateway.
local handshake_domain_multiplier = 20

-- A failed handshake never reaches the access log, so the certificate selector reports it. The event
-- travels the same authenticated syslog path as a request, carries the SNI and protocol, and is
-- bounded per second so a handshake flood cannot flood telemetry.
local tls_failure_budget_per_second = 20
local tls_failure_reasons = {
    unknown_sni = true, handshake_rate_exceeded = true, tls_disabled = true,
    certificate_unavailable = true, certificate_expired = true, certificate_invalid = true,
}

local function report_tls_failure(reason, sni)
    if not tls_failure_reasons[reason] then reason = "certificate_unavailable" end
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("capacity:tls_failures", 1, 0)
    local second = ngx.time()
    local seen = dictionary:incr("tls_failure_events:" .. second, 1, 0, 2)
    if not seen or seen > tls_failure_budget_per_second then
        dictionary:incr("capacity:tls_failures_suppressed", 1, 0)
        return
    end
    local ssl = require "ngx.ssl"
    local protocol = ssl.get_tls1_version_str and select(1, ssl.get_tls1_version_str()) or ""
    local payload = cjson.encode({
        occurred_at = ngx.utctime():gsub(" ", "T") .. "Z", event_type = "tls", tls_error = reason,
        hostname = sni, ssl_protocol = protocol or "", domain_id = 0, status = 0,
        security_action = "allow", telemetry_proof = telemetry_proof_value(),
    })
    local socket = ngx.socket.udp()
    socket:settimeout(100)
    local ok = socket:setpeername(os.getenv("EDGE_TELEMETRY_HOST") or "vector", 9000)
    if ok then socket:send("<190>cdnfoundry_edge: " .. payload) end
    socket:close()
end

function M.select_certificate()
    local ssl = require "ngx.ssl"
    local name = ssl.server_name()
    name = name and name:lower():gsub("%.$", "") or ""
    local config = state.hosts[name]
    if not config then
        ngx.shared.runtime_limits:incr("security:reason:unknown_sni", 1, 0)
        report_tls_failure("unknown_sni", name)
        error("unknown TLS SNI")
    end
    local security = config.security or {}
    local limits = effective_limits(config, security)
    local second = ngx.time()
    local budget = tonumber(limits.tls_handshakes_per_second) or 50
    local handshakes = ngx.shared.domain_limits:incr("security:tls:domain:" .. tostring(config.domain) .. ":" .. second, 1, 0, 2)
    if not handshakes or handshakes > budget * handshake_domain_multiplier then
        report_tls_failure("handshake_rate_exceeded", name)
        error("tls_handshake_rate_exceeded")
    end
    if not config.tls or config.tls.mode == "disabled" or not config.tls.certificate_id then
        report_tls_failure("tls_disabled", name)
        error("TLS disabled or certificate unavailable")
    end
    local certificate = state.certificates[config.tls.certificate_id]
    if not certificate then report_tls_failure("certificate_unavailable", name); error("TLS certificate unavailable") end
    if tonumber(certificate.expires_at) and tonumber(certificate.expires_at) <= ngx.time() then
        report_tls_failure("certificate_expired", name)
        error("TLS certificate expired")
    end
    local cached = certificate_cache[certificate.id]
    if not cached then
        local cert_der, cert_err = ssl.cert_pem_to_der((certificate.certificate_pem or "") .. (certificate.chain_pem or ""))
        local key_der, key_err = ssl.priv_key_pem_to_der(certificate.private_key_pem or "")
        if not cert_der or not key_der then
            report_tls_failure("certificate_invalid", name)
            error("invalid TLS bundle: " .. (cert_err or key_err or "unknown"))
        end
        cached = {certificate = cert_der, key = key_der}
        certificate_cache[certificate.id] = cached
        certificate_cache_order[#certificate_cache_order + 1] = certificate.id
        if #certificate_cache_order > certificate_cache_limit then
            local expired_id = table.remove(certificate_cache_order, 1)
            certificate_cache[expired_id] = nil
        end
    end
    local ok, err = ssl.clear_certs()
    if not ok then error("unable to clear bootstrap certificate: " .. (err or "unknown")) end
    ok, err = ssl.set_der_cert(cached.certificate)
    if not ok then error("unable to select certificate: " .. (err or "unknown")) end
    ok, err = ssl.set_der_priv_key(cached.key)
    if not ok then error("unable to select certificate key: " .. (err or "unknown")) end
end

local function resolve(host, networks, blocked_networks, denied, response_timeout_ms)
    if host:match("^%d+%.%d+%.%d+%.%d+$") or host:find(":", 1, true) then
        if blocked(host, networks, blocked_networks, denied) then return nil, "blocked_destination" end
        return host
    end
    local r = resolver:new{nameservers={"127.0.0.11"}, retrans=2, timeout=1000}
    if not r then return nil, "dns_resolution_failed" end
    -- Individual resolver timeouts include retries and TCP fallback. Race the
    -- complete A/AAAA lookup against one deadline, then close all its sockets.
    local budget = math.min(3000, math.max(500, tonumber(response_timeout_ms) or 3000))
    local query = ngx.thread.spawn(function()
        local selected, record_count = nil, 0
        for _, qtype in ipairs({r.TYPE_A, r.TYPE_AAAA}) do
            local answers = r:query(host, {qtype=qtype})
            if not answers or (answers.errcode and answers.errcode ~= 0) then
                return nil, "dns_resolution_failed"
            end
            record_count = record_count + #answers
            if record_count > 64 then return nil, "dns_response_limit" end
            for _, answer in ipairs(answers) do
                if answer.address then
                    if blocked(answer.address, networks, blocked_networks, denied) then return nil, "blocked_destination" end
                    selected = selected or answer.address
                end
            end
        end
        if not selected then return nil, "dns_resolution_failed" end
        return selected
    end)
    local deadline = ngx.thread.spawn(function()
        ngx.sleep(budget / 1000)
        return nil, "dns_resolution_timeout"
    end)
    local ok, address, failure = ngx.thread.wait(query, deadline)
    if coroutine.status(query) ~= "dead" then ngx.thread.kill(query) end
    if coroutine.status(deadline) ~= "dead" then ngx.thread.kill(deadline) end
    r:destroy()
    if not ok then return nil, "dns_resolution_failed" end
    return address, failure
end

local function reject(status)
    return security_reject(status, "malformed_request")
end

-- The connection address is already the PROXY-protocol address when the peer is
-- the gateway (nginx trusts PROXY headers only from the gateway's networks).
-- X-Forwarded-For is read only when that address is a configured trusted proxy,
-- walking from the right and stopping at the first address that is not one.
local function client_address(security)
    local remote = ngx.var.remote_addr or ""
    local trusted = security.trusted_proxy_cidrs
    if type(trusted) ~= "table" or #trusted == 0 or not allowed(remote, trusted) then return remote end
    local entries = {}
    for entry in (ngx.var.http_x_forwarded_for or ""):gmatch("[^,]+") do
        entries[#entries + 1] = entry:match("^%s*(.-)%s*$")
        if #entries > 32 then return remote end
    end
    for index = #entries, 1, -1 do
        local entry = entries[index]
        if not packed_address(entry) then return remote end
        if not allowed(entry, trusted) then return entry end
    end
    return entries[1] or remote
end

local function geography(ip)
    if not geo_database then return "unknown", "unknown" end
    local ok, result = pcall(geo_database.lookup, geo_database, ip)
    if not ok or not result then return "unknown", "unknown" end
    local ok_country, country = pcall(result.get, result, "country", "iso_code")
    local ok_continent, continent = pcall(result.get, result, "continent", "code")
    return ok_country and country or "unknown", ok_continent and continent or "unknown"
end

-- The autonomous system number of a client, or 0 when the ASN database is absent or has no entry.
local function autonomous_system(ip)
    if not asn_database then return 0 end
    local ok, result = pcall(asn_database.lookup, asn_database, ip)
    if not ok or not result then return 0 end
    local ok_number, number = pcall(result.get, result, "autonomous_system_number")
    number = ok_number and tonumber(number) or 0
    if number < 0 or number > 4294967295 then return 0 end
    return math.floor(number)
end

local function rule_matches(rule, ip, country, continent)
    if rule.match_type == "ip" then return ip:lower() == tostring(rule.value):lower() end
    if rule.match_type == "cidr" then return allowed(ip, {rule.value}) end
    if rule.match_type == "country" then return country ~= "unknown" and country == rule.value end
    if rule.match_type == "continent" then return continent ~= "unknown" and continent == rule.value end
    return false
end

local function emergency_actions(dictionary)
    local raw = dictionary:get("emergency:active")
    local expires = tonumber(dictionary:get("emergency:expires_at")) or 0
    if not raw or (expires > 0 and expires <= ngx.time()) then
        if raw then dictionary:delete("emergency:active"); dictionary:delete("emergency:expires_at") end
        return {}
    end
    local decoded = cjson.decode(raw)
    if type(decoded) ~= "table" then return {} end
    local actions = {}
    for _, action in ipairs(decoded) do actions[action] = true end
    return actions
end

local function body_size()
    local length = tonumber(ngx.var.http_content_length)
    if length then return length end
    if not ngx.var.http_transfer_encoding then return 0 end
    ngx.req.read_body()
    local data = ngx.req.get_body_data()
    if data then return #data end
    local file_path = ngx.req.get_body_file()
    if not file_path then return 0 end
    local file = io.open(file_path, "rb")
    if not file then return 0 end
    local size = file:seek("end") or 0
    file:close()
    return size
end

local function request_header_size()
    local ok, raw = pcall(ngx.req.raw_header)
    if ok and raw then return #raw end
    local headers, err = ngx.req.get_headers(100, true)
    if err == "truncated" then return math.huge end
    local size = #(ngx.req.get_method() or "") + #(ngx.var.request_uri or "") + 16
    for name, value in pairs(headers or {}) do
        if type(value) == "table" then
            for _, item in ipairs(value) do size = size + #tostring(name) + #tostring(item) + 4 end
        else
            size = size + #tostring(name) + #tostring(value) + 4
        end
    end
    return size
end

local PURGE_TTL_SECONDS = 31536000

-- The first path rule whose prefix or suffix matches the raw request path wins.
-- The list is bounded to 16 rules by the control plane; a longer list is cut here.
local function matching_path_rule(cache)
    local rules = cache.path_rules
    if type(rules) ~= "table" then return nil end
    local path = (ngx.var.request_uri or ""):match("^[^?]*") or ""
    for index, rule in ipairs(rules) do
        if index > 16 then break end
        local value = type(rule) == "table" and rule.value
        if type(value) == "string" and value ~= "" then
            if rule.match == "prefix" and path:sub(1, #value) == value then return rule end
            if rule.match == "suffix" and #path >= #value and path:sub(-#value) == value then return rule end
        end
    end
    return nil
end

-- Returns the cache key, its edge TTL, and the resource base. A request whose
-- target has no canonical form returns an unusable key with a zero TTL and a
-- reason, so it is never cached.
local function request_cache_key(domain, host, cache)
    local query_policy = cache.query_policy or (cache.include_query_string == false and "ignore_all" or "include_all")
    local target, reason = cache_target.target(ngx.var.request_uri or "", query_policy, cache.query_parameters)
    if not target then return "uncacheable|" .. tostring(reason), 0, "uncacheable", reason end
    local base = (ngx.var.scheme or "http") .. "|" .. host .. "|" .. target
    local dictionary = ngx.shared.runtime_limits
    local configured_epoch = tonumber(cache.epoch) or 1
    local runtime_epoch = tonumber(dictionary:get("cache:epoch:" .. domain)) or 0
    local epoch = math.max(configured_epoch, runtime_epoch)
    local generation = ngx.shared.cache_purges:get("u:" .. ngx.md5(base)) or 0
    local ttl = math.max(0, math.min(31536000, tonumber(cache.edge_ttl_seconds) or 0))
    local path_rule = matching_path_rule(cache)
    if path_rule and path_rule.action == "bypass" then ttl = 0 end
    if path_rule and path_rule.action == "ttl" then ttl = math.max(0, math.min(31536000, tonumber(path_rule.ttl_seconds) or 0)) end
    local policy_query_parameters = {}
    for _, name in ipairs(cache.query_parameters or {}) do policy_query_parameters[#policy_query_parameters + 1] = name end
    table.sort(policy_query_parameters)
    local status_ttls, policy_status_ttls = cache.status_ttl_seconds or {}, {}
    for _, status in ipairs({"200", "203", "204", "206", "301", "302", "404"}) do
        policy_status_ttls[#policy_status_ttls + 1] = status .. "=" .. tostring(status_ttls[status] or 0)
    end
    local policy = table.concat({
        tostring(ttl), tostring(math.max(0, math.min(31536000, tonumber(cache.browser_ttl_seconds) or 0))),
        tostring(math.max(1024, math.min(1073741824, tonumber(cache.maximum_object_bytes) or 104857600))),
        cache.respect_origin_headers == false and "0" or "1",
        tostring(math.max(0, math.min(86400, tonumber(cache.stale_if_error_seconds) or 0))),
        tostring(math.max(0, math.min(86400, tonumber(cache.stale_while_revalidate_seconds) or 0))),
        query_policy, table.concat(policy_query_parameters, ","),
        cache.mode or "normal", tostring(tonumber(cache.admission_requests) or 1),
        tostring(tonumber(cache.maximum_variants_per_resource) or 32),
        table.concat(policy_status_ttls, ","),
        path_rule and (path_rule.action .. "=" .. tostring(path_rule.ttl_seconds or 0)) or "-",
    }, ":")
    return base .. "|e" .. epoch .. "|g" .. generation .. "|p" .. ngx.md5(policy), ttl, base
end

-- A request that carries any cookie is personal until the domain says otherwise:
-- it bypasses the cache unless every cookie name is on the ignore list.
local function cookie_bypassed(ignored_names)
    local raw = ngx.var.http_cookie or ""
    if raw == "" then return false end
    local ignored, prefixes = {}, {}
    for _, name in ipairs(ignored_names or {}) do
        if name:sub(-1) == "*" then prefixes[#prefixes + 1] = name:sub(1, -2) else ignored[name] = true end
    end
    local found = false
    for name in raw:gmatch("%s*([^=;%s]+)%s*=") do
        found = true
        if not ignored[name] then
            local matched = false
            for _, prefix in ipairs(prefixes) do
                if name:sub(1, #prefix) == prefix then matched = true; break end
            end
            if not matched then return true end
        end
    end
    -- A Cookie header with no parsable name is still a cookie.
    return not found
end

local function accepts_encoding(raw, encoding)
    for item in (raw or ""):lower():gmatch("[^,]+") do
        local name = item:match("^%s*([%w%*%-]+)")
        local quality = item:match(";%s*q%s*=%s*([%d%.]+)")
        if (name == encoding or name == "*") and (not quality or (tonumber(quality) or 0) > 0) then return true end
    end
    return false
end

local function prepare_compression(config, emergency)
    local policy = config.compression or {}
    local profile = policy.profile_name or "standard"
    if profile ~= "off" and profile ~= "standard" and profile ~= "maximum_savings" then profile = "off" end
    ngx.var.cdn_compression_profile = profile
    local maximum_active = math.max(0, math.min(64, tonumber(policy.maximum_active_requests) or 0))
    local active_key = "compression:active"
    local active = ngx.shared.runtime_limits:incr(active_key, 1, 0)
    ngx.var.cdn_compression_connection_key = active_key
    local disabled = os.getenv("EDGE_COMPRESSION_DISABLED") == "1" or emergency.disable_compression
    local pressure = maximum_active == 0 or (active and active > maximum_active)
        or (tonumber(ngx.var.connections_active) or 0) > 2048
    local raw = ngx.var.http_accept_encoding or ""
    if disabled or profile == "off" or ngx.var.http_range then
        ngx.req.clear_header("Accept-Encoding")
        ngx.var.cdn_compression_fallback = disabled and "emergency_disabled"
            or (ngx.var.http_range and "range_identity" or "profile_off")
    elseif pressure then
        ngx.req.clear_header("Accept-Encoding")
        ngx.var.cdn_compression_fallback = "cpu_pressure_identity"
        ngx.shared.runtime_limits:incr("compression:fallbacks", 1, 0)
    elseif profile == "maximum_savings" and policy.brotli == true and accepts_encoding(raw, "br") then
        ngx.req.set_header("Accept-Encoding", "br")
    elseif policy.gzip == true and accepts_encoding(raw, "gzip") then
        ngx.req.set_header("Accept-Encoding", "gzip")
        if profile == "maximum_savings" then ngx.var.cdn_compression_fallback = "client_gzip" end
    else
        ngx.req.clear_header("Accept-Encoding")
        ngx.var.cdn_compression_fallback = "client_identity"
    end
end

function M.prepare_waf()
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    local waf = config and config.waf or {}
    local profile = waf.name or "off"
    local mode = profile == "monitor" and "monitor"
        or ((profile == "balanced" or profile == "strict") and "block" or "off")
    local threshold = math.max(1, math.min(100, tonumber(waf.inbound_threshold) or 5))
    local paranoia = math.max(1, math.min(4, tonumber(waf.paranoia_level) or 1))
    -- The incident profile forces blocking at the strict profile's sensitivity, whatever the domain's own profile.
    local incident = config and incident_for(config)
    if incident and type(incident.waf) == "table" and incident.waf.mode == "block" then
        mode = "block"
        threshold = math.min(threshold, 3)
        paranoia = math.max(paranoia, 2)
    end
    ngx.var.cdn_waf_profile = profile
    ngx.var.cdn_waf_action = mode == "monitor" and "monitor" or (mode == "block" and "inspect" or "off")
    -- These headers are overwritten before ModSecurity runs and removed again
    -- before proxying. A client therefore cannot select another tenant's mode.
    ngx.req.set_header("X-CDNF-Internal-WAF-Mode", mode)
    ngx.req.set_header("X-CDNF-Internal-WAF-Threshold", tostring(threshold))
    ngx.req.set_header("X-CDNF-Internal-WAF-Paranoia", tostring(paranoia))
    -- Exclusions are not honored: switching a whole request to monitor mode let
    -- an attacker bypass CRS by adding a literal name. See docs/guides/managed-waf.md.
end

local function request_body_safety(config)
    local waf = config.waf or {}
    if waf.name == "off" then return end
    local limit = math.max(1024, math.min(1048576, tonumber(waf.body_limit_bytes) or 1048576))
    local length = tonumber(ngx.var.http_content_length) or 0
    if length > limit then
        ngx.var.cdn_waf_body_limit = "exceeded"
        if waf.blocking == true then return security_reject(413, "waf_body_limit") end
        return
    end
    if length > 0 and (ngx.var.http_content_type or ""):lower():find("application/json", 1, true) then
        ngx.req.read_body()
        local body = ngx.req.get_body_data()
        if body and #body <= limit and not cjson.decode(body) then
            return security_reject(400, "malformed_request_body")
        end
    end
end

local function certificate_covers(host, names)
    for _, name in ipairs(names or {}) do
        name = tostring(name):lower()
        if name == host then return true end
        local suffix = name:match("^%*(%..+)$")
        if suffix and host:sub(-#suffix) == suffix and not host:sub(1, #host - #suffix):find(".", 1, true)
            and #host > #suffix then
            return true
        end
    end
    return false
end

-- The certificate is chosen by SNI and the tenant by Host. A TLS request may
-- only address a Host that the certificate selected for its SNI covers.
local function tls_host_matches_sni(host)
    if ngx.var.https ~= "on" then return true end
    local sni = (ngx.var.ssl_server_name or ""):lower():gsub("%.$", "")
    if sni == "" then return false end
    if sni == host then return true end
    local sni_config = state.hosts[sni]
    local certificate_id = sni_config and sni_config.tls and sni_config.tls.certificate_id
    local certificate = certificate_id and state.certificates[certificate_id]
    return certificate ~= nil and certificate_covers(host, certificate.names)
end

function M.access()
    ngx.req.clear_header("X-CDN-Client-Address")
    ngx.req.clear_header("X-CDN-Cache-Bypass")
    ngx.var.cdn_telemetry_proof = telemetry_proof_value()
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("capacity:requests:" .. ngx.time(), 1, 0, 2)
    if dictionary:get("control:drained") == true then return security_reject(503, "edge_emergency_mode") end
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then return security_reject(421, "unknown_host") end
    if not tls_host_matches_sni(host) then return security_reject(421, "sni_host_mismatch") end
    ngx.var.cdn_original_host = host
    ngx.var.cdn_domain_id = tostring(config.domain_id or 0)
    ngx.var.cdn_edge_id = os.getenv("EDGE_CELL_NAME") or "unknown"
    ngx.ctx.security_domain = config.domain_id or config.domain
    ngx.ctx.security_hostname = host
    if config.settings and config.settings.enabled == false then return reject(503) end
    M.prepare_waf()
    local body_result = request_body_safety(config)
    if body_result then return body_result end
    local security = config.security or {}
    local limits = effective_limits(config, security)
    local emergency = emergency_actions(dictionary)
    if emergency.return_maintenance_response then return security_reject(503, "edge_emergency_mode") end
    if security.state == "quarantined" then return security_reject(429, "domain_quarantined") end
    local method = ngx.req.get_method()
    if emergency.allow_get_head_only and method ~= "GET" and method ~= "HEAD" then return security_reject(405, "edge_emergency_mode") end
    local incident = incident_for(config)
    if incident then
        local incident_allows = false
        for _, allowed_method in ipairs(incident.methods or {}) do
            if method == allowed_method then incident_allows = true; break end
        end
        if not incident_allows then return security_reject(405, "incident_method") end
    end
    local method_allowed = false
    for _, allowed_method in ipairs(security.allowed_methods or {"GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"}) do
        if method == allowed_method then method_allowed = true; break end
    end
    if not method_allowed then return security_reject(405, "invalid_method") end
    if request_header_size() > (tonumber(limits.maximum_header_size) or 32768) then return security_reject(431, "header_too_large") end
    local size = body_size()
    if emergency.disable_request_bodies and size > 0 then return security_reject(413, "edge_emergency_mode") end
    if size > (tonumber(limits.maximum_request_body_size) or 8388608) then return security_reject(413, "body_too_large") end
    if incident and size > (tonumber(incident.max_body_bytes) or 1048576) then return security_reject(413, "incident_body_size") end
    local client = client_address(security)
    local country, continent = geography(client)
    ngx.var.cdn_client_ip = client
    -- One internal header carries the address to the origin hop; nothing from
    -- the client is ever trusted in its place.
    ngx.req.set_header("X-CDN-Client-Address", client)
    ngx.var.cdn_country = country or "ZZ"
    ngx.var.cdn_continent = continent or "ZZ"
    ngx.var.cdn_asn = tostring(autonomous_system(client))
    local trusted = false
    for _, rule in ipairs(security.rules or {}) do
        if rule_matches(rule, client, country, continent) then
            if rule.action == "block" then return security_reject(403, "domain_restricted") end
            -- A client the domain's own rules allow is trusted and is not challenged.
            if rule.action == "allow" then trusted = true end
            break
        end
    end
    if incident and incident.challenge == true then
        local challenged = enforce_challenge(config, incident, client, trusted)
        if challenged then return challenged end
    end
    local second = ngx.time()
    local client_key = "security:req:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client) .. ":" .. second
    local domain_key = "security:req:domain:" .. tostring(config.domain) .. ":" .. second
    -- Attacker-controlled client cardinality must never evict control and
    -- per-domain counters. Nginx's limit_req remains a fixed-memory fallback.
    local client_requests = ngx.shared.traffic_limits:incr(client_key, 1, 0, 2)
    local domain_dictionary = ngx.shared.domain_limits
    local domain_requests = domain_dictionary:incr(domain_key, 1, 0, 2)
    if not domain_requests then return security_reject(503, "domain_rate_exceeded") end
    local rps = tonumber(limits.requests_per_second) or 100
    local burst = tonumber(limits.request_burst) or 200
    if client_requests and client_requests > rps + burst then return security_reject(429, "client_rate_exceeded") end
    if domain_requests and domain_requests > rps * 8 + burst then return security_reject(429, "domain_rate_exceeded") end
    local client_connection_key = "security:conn:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client)
    local domain_connection_key = "security:conn:domain:" .. tostring(config.domain)
    local client_connections = ngx.shared.traffic_limits:incr(client_connection_key, 1, 0)
    local domain_connections = domain_dictionary:incr(domain_connection_key, 1, 0)
    if not domain_connections then
        if client_connections then ngx.shared.traffic_limits:incr(client_connection_key, -1, 0) end
        return security_reject(503, "domain_connections_exceeded")
    end
    if client_connections and client_connections > (tonumber(limits.connections_per_client) or 64) then
        ngx.shared.traffic_limits:incr(client_connection_key, -1, 0)
        domain_dictionary:incr(domain_connection_key, -1, 0)
        return security_reject(429, "client_connections_exceeded")
    end
    if domain_connections and domain_connections > (tonumber(limits.connections_per_domain) or 512) then
        ngx.shared.traffic_limits:incr(client_connection_key, -1, 0)
        domain_dictionary:incr(domain_connection_key, -1, 0)
        return security_reject(429, "domain_connections_exceeded")
    end
    -- ngx.exec enters a named cache location and may replace ngx.ctx. Nginx
    -- request variables survive that redirect and let the log phase release
    -- the active-request counters reliably.
    ngx.var.cdn_security_client_connection_key = client_connections and client_connection_key or ""
    ngx.var.cdn_security_domain_connection_key = domain_connection_key
    if config.settings and config.settings.redirect_https == true and ngx.var.scheme == "http" then
        return ngx.redirect("https://" .. host .. ngx.var.request_uri, 308)
    end
    if config.settings and type(config.settings.http_versions) == "table" then
        local current = tostring(ngx.req.http_version())
        local accepted = false
        for _, version in ipairs(config.settings.http_versions) do
            if tostring(version) == current or tostring(version) .. ".0" == current then accepted = true; break end
        end
        if not accepted then return reject(505) end
    end
    if config.settings and type(config.settings.maintenance) == "table" then
        ngx.status = 503; ngx.header["Content-Type"] = "text/plain"; ngx.say(config.settings.maintenance.body or "Service unavailable"); return ngx.exit(503)
    end
    local cache = config.cache or {}
    prepare_compression(config, emergency)
    local cache_key, cache_ttl, cache_resource, key_reason = request_cache_key(config.domain, host, cache)
    if key_reason == "too_many_query_arguments" then ngx.var.cdn_security_reason = "cache_abuse_detected" end
    local development_until = tonumber(cache.development_mode_until) or 0
    local cacheable = cache.enabled == true and cache_ttl > 0
        and (ngx.req.get_method() == "GET" or ngx.req.get_method() == "HEAD")
        and not ngx.var.http_authorization and not ngx.var.http_range
        and not cookie_bypassed(cache.ignore_cookie_names)
        and development_until <= ngx.time()
        and #cache_key <= (tonumber(limits.maximum_cache_key_length) or 4096)
    local profile = cache.profile or {}
    local profile_name = cache.profile_name or "standard"
    if profile_name ~= "small" and profile_name ~= "standard" and profile_name ~= "large" and profile_name ~= "streaming" then
        profile_name = "standard"
    end
    ngx.var.cdn_cache_zone = "cache_" .. profile_name
    local admissions = dictionary:incr("security:cache:" .. tostring(config.domain) .. ":" .. second, 1, 0, 2)
    local cache_dictionary = ngx.shared.cache_limits
    local admission_hits = cache_dictionary:incr("cache:admit:" .. ngx.md5(cache_key), 1, 0, 60)
    local admission_allowed = (not admissions or admissions <= (tonumber(profile.admissions_per_second) or tonumber(limits.cache_admissions_per_second) or 50))
        and (not admission_hits or admission_hits >= (tonumber(cache.admission_requests) or 1))
    local variant_resource = "cache:variants:" .. ngx.md5(cache_resource)
    if cache_dictionary:add(variant_resource .. ":" .. ngx.md5(cache_key), true, 60) then
        cache_dictionary:incr(variant_resource, 1, 0, 60)
    end
    local variants = tonumber(cache_dictionary:get(variant_resource)) or 0
    if not admission_hits then admission_allowed = false end
    if variants > (tonumber(cache.maximum_variants_per_resource) or 32) then admission_allowed = false end
    if #cache_key > (tonumber(limits.maximum_cache_key_length) or 4096) then ngx.var.cdn_security_reason = "cache_abuse_detected" end
    ngx.var.cdn_cache_key = cache_key
    ngx.var.cdn_cache_bypass = cacheable and "0" or "1"
    ngx.var.cdn_cache_no_store = cacheable and admission_allowed and "0" or "1"
    ngx.var.cdn_cache_edge_ttl = tostring(cache_ttl)
    ngx.var.cdn_cache_browser_ttl = tostring(math.max(0, math.min(31536000, tonumber(cache.browser_ttl_seconds) or 0)))
    ngx.var.cdn_cache_max_object = tostring(math.max(1024, math.min(1073741824, tonumber(cache.maximum_object_bytes) or 104857600)))
    ngx.var.cdn_cache_stale = tostring(math.max(0, math.min(86400, tonumber(cache.stale_if_error_seconds) or 0)))
    ngx.var.cdn_cache_swr = tostring(math.max(0, math.min(86400, tonumber(cache.stale_while_revalidate_seconds) or 0)))
    ngx.var.cdn_cache_mode = cache.mode or "normal"
    ngx.var.cdn_cache_respect_origin = cache.respect_origin_headers == false and "0" or "1"
    local maximum = tonumber(cache.maximum_object_bytes) or 104857600
    if maximum <= 1048576 then return ngx.exec("@cache_1m") end
    if maximum <= 10485760 then return ngx.exec("@cache_10m") end
    if maximum <= 104857600 then return ngx.exec("@cache_100m") end
    return ngx.exec("@cache_1g")
end

function M.invalid_method()
    ngx.var.cdn_telemetry_proof = telemetry_proof_value()
    return security_reject(405, ngx.var.cdn_security_reason ~= "" and ngx.var.cdn_security_reason or "invalid_method")
end

function M.origin_access()
    local dictionary = ngx.shared.runtime_limits
    local host = (ngx.var.cdn_original_host ~= "" and ngx.var.cdn_original_host or ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then return security_reject(421, "unknown_host") end
    ngx.req.clear_header("X-CDNF-Internal-WAF-Mode")
    ngx.req.clear_header("X-CDNF-Internal-WAF-Threshold")
    ngx.req.clear_header("X-CDNF-Internal-WAF-Paranoia")
    ngx.ctx.security_domain = config.domain_id or config.domain
    ngx.ctx.security_hostname = host
    local security = config.security or {}
    local limits = effective_limits(config, security)
    local emergency = emergency_actions(dictionary)
    local cache_mode = config.cache and config.cache.mode or "normal"
    if emergency.serve_cache_only or emergency.serve_stale_only or cache_mode == "cache_only" or cache_mode == "stale_only" then
        return security_reject(503, cache_mode == "normal" and "edge_emergency_mode" or "cache_mode_origin_disabled")
    end
    local primary = config.origin
    local failover = primary.backup and primary.failover or nil
    local state_key = "origin:failover:" .. host
    local active_role = failover and (dictionary:get(state_key .. ":active") or "primary") or "primary"
    local now = ngx.now()
    local failback_at = tonumber(dictionary:get(state_key .. ":failback_at")) or 0
    local role = active_role
    if failover and active_role == "backup" and now >= failback_at then role = "primary" end
    local origin = role == "backup" and primary.backup or primary
    local transition = failover and (dictionary:get(state_key .. ":reason") or "none") or "none"
    if not failover then
        local circuit_key = "security:origin:open:" .. tostring(config.domain)
        local open_until = tonumber(dictionary:get(circuit_key)) or 0
        if open_until > now then return security_reject(503, "origin_circuit_open") end
    end
    local origin_key = "security:origin:connections:" .. tostring(config.domain) .. ":" .. role
    local active = ngx.shared.domain_limits:incr(origin_key, 1, 0)
    if not active then return security_reject(503, "origin_capacity_exceeded") end
    if active and active > (tonumber(limits.origin_max_connections) or 128) then
        ngx.shared.domain_limits:incr(origin_key, -1, 0)
        return security_reject(503, "origin_capacity_exceeded")
    end
    -- Keep the acquired reservation across named proxy/error redirects, which
    -- replace ngx.ctx. Rejected requests must never synthesize a reservation.
    ngx.var.cdn_origin_connection_key = origin_key
    ngx.ctx.origin_domain = tostring(config.domain)
    ngx.ctx.origin_hostname = host
    ngx.ctx.origin_role = role
    -- Named error locations retain variables after ngx.ctx is replaced.
    ngx.var.cdn_origin_role = role
    ngx.var.cdn_origin_transition = transition
    ngx.ctx.origin_failover = failover
    ngx.ctx.origin_failure_threshold = tonumber(limits.origin_failure_threshold) or 10
    ngx.ctx.origin_recovery_timeout = tonumber(limits.origin_recovery_timeout) or 30
    dictionary:incr("capacity:origin_connections", 1, 0)
    if not allowed_origin_ports[tonumber(origin.port)] then
        ngx.log(ngx.WARN, "origin rejected: port not allowed")
        ngx.header["X-CDNF-Error"] = "blocked_destination"
        return ngx.exit(502)
    end
    local address, err = resolve(origin.host, origin.private_allowlist, origin.blocked_networks, origin.blocked_addresses, origin.response_timeout_ms)
    if not address then
        ngx.log(ngx.WARN, "origin rejected: ", err)
        ngx.header["X-CDNF-Error"] = err
        -- Leave this as an ordinary upstream failure. The outer cache can then
        -- apply its bounded stale-if-error policy; security-controlled 503s
        -- retain their explicit reason and are never disguised as origin loss.
        return ngx.exit(502)
    end
    if address:find(":", 1, true) then address = "[" .. address .. "]" end
    ngx.var.origin_scheme = origin.scheme
    ngx.var.origin_address = address
    ngx.var.origin_port = tostring(origin.port)
    ngx.var.origin_host_header = origin.host_header
    ngx.var.origin_sni = origin.sni or origin.host_header
    ngx.var.origin_connection = ""
    ngx.var.origin_upgrade = ""
    ngx.var.origin_connect_timeout = tostring(math.min((tonumber(limits.origin_connect_timeout) or 3) * 1000, math.max(100, math.min(10000, tonumber(origin.connect_timeout_ms) or 1000))))
    ngx.var.origin_response_timeout = tostring(math.min((tonumber(limits.origin_read_timeout) or 30) * 1000, math.max(500, math.min(60000, tonumber(origin.response_timeout_ms) or 5000))))
    local retry_limit = emergency.disable_origin_retries and 0 or (tonumber(limits.origin_retry_limit) or 0)
    ngx.var.origin_retry_count = tostring(math.max(0, math.min(retry_limit, tonumber(origin.retry_count) or tonumber(config.settings and config.settings.retry_count) or 0)))
    ngx.header["X-CDNF-Origin"] = role
    ngx.header["X-CDNF-Origin-Transition"] = transition
    if origin.websocket == true and (ngx.var.http_upgrade or ""):lower() == "websocket" then
        ngx.var.origin_connection = "upgrade"
        ngx.var.origin_upgrade = "websocket"
    end
    ngx.req.clear_header("Forwarded"); ngx.req.clear_header("X-Forwarded-For"); ngx.req.clear_header("X-Forwarded-Host"); ngx.req.clear_header("X-Forwarded-Proto")
    ngx.req.clear_header("Proxy-Connection"); ngx.req.clear_header("Keep-Alive"); ngx.req.clear_header("TE"); ngx.req.clear_header("Trailer"); ngx.req.clear_header("Upgrade")
    if origin.scheme == "https" and origin.verify_tls == true then return ngx.exec("@proxy_verified") end
    if origin.scheme == "https" then return ngx.exec("@proxy_unverified_https") end
    return ngx.exec("@proxy_http")
end

function M.prepare_cache_response()
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then return end
    local cache = config.cache or {}
    local status = tonumber(ngx.status) or 0
    local headers = ngx.header
    -- A request the cache bypassed (a cookie, Authorization, a range) is never
    -- stored, so its response keeps exactly the origin's cache headers. Adding
    -- "public" here would let a downstream cache keep a personal response.
    if ngx.var.http_x_cdn_cache_bypass == "1" then
        headers["X-CDNF-No-Store"] = "1"
        return
    end
    local cache_control = cache_control_directives(headers["Cache-Control"])
    local status_ttls = cache.status_ttl_seconds or {["200"] = cache.edge_ttl_seconds or 0}
    local status_ttl = tonumber(status_ttls[tostring(status)])
    local no_store = status_ttl == nil or status_ttl <= 0 or headers["Set-Cookie"] ~= nil
        or cache_control["private"] ~= nil or cache_control["no-store"] ~= nil or cache_control["no-cache"] ~= nil
    local vary = headers["Vary"]
    if vary then
        local tokens, count = {}, 0
        for token in tostring(vary):lower():gmatch("[^,%s]+") do tokens[token] = true; count = count + 1 end
        if #tostring(vary) > 256 or count > 4 or tokens["*"] or (count > 0 and not (count == 1 and tokens["accept-encoding"])) then no_store = true end
    end
    local profile_maximum = tonumber(cache.profile and cache.profile.maximum_object_bytes) or 104857600
    local maximum = math.max(1048576, math.min(1073741824, tonumber(cache.maximum_object_bytes) or 104857600, profile_maximum))
    local length = tonumber(headers["Content-Length"])
    if length and length > maximum then no_store = true end
    if no_store then
        headers["X-CDNF-No-Store"] = "1"
        return
    end
    local edge_ttl = math.max(0, math.min(31536000, status_ttl or tonumber(cache.edge_ttl_seconds) or 0))
    local path_rule = matching_path_rule(cache)
    if path_rule and path_rule.action == "ttl" then
        -- An explicit path TTL replaces the origin's lifetime for the statuses the domain already allows.
        edge_ttl = math.max(0, math.min(31536000, tonumber(path_rule.ttl_seconds) or 0))
        headers["Expires"] = nil
    elseif cache.respect_origin_headers ~= false then
        local origin_ttl = tonumber(cache_control["s-maxage"] or cache_control["max-age"])
        if not origin_ttl and headers["Expires"] then
            local expires = ngx.parse_http_time(tostring(headers["Expires"]))
            if expires then origin_ttl = math.max(0, expires - ngx.time()) end
        end
        if origin_ttl then edge_ttl = math.max(0, math.min(31536000, origin_ttl)) end
    else
        headers["Expires"] = nil
    end
    if edge_ttl <= 0 then
        headers["X-CDNF-No-Store"] = "1"
        return
    end
    local browser = math.max(0, math.min(31536000, tonumber(cache.browser_ttl_seconds) or 0))
    local stale = math.max(0, math.min(86400, tonumber(cache.stale_if_error_seconds) or 0))
    local swr = math.max(0, math.min(86400, tonumber(cache.stale_while_revalidate_seconds) or 0))
    headers["Cache-Control"] = "public, s-maxage=" .. edge_ttl .. ", max-age=" .. browser
        .. (stale > 0 and ", stale-if-error=" .. stale or "") .. (swr > 0 and ", stale-while-revalidate=" .. swr or "")
    headers["X-Accel-Expires"] = tostring(edge_ttl)
end

function M.cache_status()
    local no_store = ngx.var.upstream_http_x_cdnf_no_store == "1"
    local admission_bypass = ngx.var.cdn_cache_no_store == "1" and ngx.var.upstream_cache_status ~= "HIT"
    ngx.header["X-CDNF-Cache"] = (ngx.var.cdn_cache_bypass == "1" or no_store or admission_bypass)
        and "BYPASS" or (ngx.var.upstream_cache_status or "MISS")
    if not diagnostic_headers_enabled() then
        -- The access log reads the upstream copies of these headers, so telemetry is unchanged.
        for _, name in ipairs({"X-CDNF-Cache", "X-CDNF-Origin", "X-CDNF-Origin-Transition", "X-CDNF-Error", "X-CDNF-Security-Reason", "Server"}) do
            ngx.header[name] = nil
        end
    end
end

function M.compression_response()
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    local policy = config and config.compression or {}
    local length = tonumber(ngx.header["Content-Length"])
    local maximum = math.max(0, math.min(10485760, tonumber(policy.maximum_bytes) or 0))
    if maximum == 0 or (length and length > maximum) or ngx.status == 206 or ngx.header["Content-Range"] then
        ngx.req.clear_header("Accept-Encoding")
        ngx.var.cdn_compression_fallback = (ngx.status == 206 or ngx.header["Content-Range"])
            and "range_identity" or "response_too_large"
    end
end

cache_control_directives = function(raw)
    local directives = {}
    for part in (raw or ""):lower():gmatch("[^,]+") do
        local name, value = part:match("^%s*([%w%-]+)%s*=?%s*(.-)%s*$")
        if name then directives[name] = value:gsub('^"', ''):gsub('"$', '') end
    end
    return directives
end

function M.balance()
    -- The peer is numeric, so a rebinding DNS answer cannot change the TCP
    -- destination after this check. Recheck on every retry as well.
    local hostname = (ngx.var.cdn_original_host ~= "" and ngx.var.cdn_original_host or ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[hostname]
    local origin = config and config.origin
    if origin and ngx.var.cdn_origin_role == "backup" then origin = origin.backup end
    local peer = (ngx.var.origin_address or ""):gsub("^%[", ""):gsub("%]$", "")
    if not origin or not allowed_origin_ports[tonumber(ngx.var.origin_port)]
        or blocked(peer, origin.private_allowlist, origin.blocked_networks, origin.blocked_addresses) then
        error("blocked_destination")
    end
    local tls_name = ngx.var.origin_sni
    if tls_name == "" then
        tls_name = nil
    end
    local ok, err = balancer.set_current_peer(
        ngx.var.origin_address,
        tonumber(ngx.var.origin_port),
        tls_name
    )
    if not ok then error("unable to select origin: " .. (err or "unknown")) end
    local connect_timeout = tonumber(ngx.var.origin_connect_timeout) or 1000
    local response_timeout = tonumber(ngx.var.origin_response_timeout) or 5000
    local retry_count = math.max(0, math.min(2, tonumber(ngx.var.origin_retry_count) or 0))
    balancer.set_timeouts(connect_timeout / 1000, response_timeout / 1000, response_timeout / 1000)
    local keepalive_ok, keepalive_err = balancer.enable_keepalive(30, 1000)
    if not keepalive_ok then error("unable to enable origin keepalive: " .. (keepalive_err or "unknown")) end
    -- The budget excludes the current attempt. Replenishing it on every
    -- balancer invocation would allow a failing origin to retry indefinitely.
    if not ngx.ctx.origin_retry_budget_set then
        ngx.ctx.origin_retry_budget_set = true
        if retry_count > 0 then
            local retry_ok, retry_err = balancer.set_more_tries(retry_count)
            if not retry_ok then error("unable to set origin retry budget: " .. (retry_err or "unknown")) end
        end
    end
end

function M.record_passive_failure()
    if (ngx.var.cdn_origin_connection_key or "") == "" then return end
    local status = tonumber((ngx.var.upstream_status or ""):match("(%d+)%s*$"))
    if status and status < 500 then
        M.origin_done()
        return
    end
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then
        -- A runtime update can remove this hostname while its origin request
        -- is in flight. The acquired reservation still belongs to the request.
        M.origin_done()
        return
    end
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("passive:" .. host, 1, 0)
    dictionary:set("passive-status:" .. host, status or 0)
    dictionary:set("passive-time:" .. host, ngx.time())
    M.origin_done()
end

-- ModSecurity runs inside the cache locations and does not hand its verdict
-- back to NGINX variables. A 403 that no origin produced (no upstream status,
-- no cache lookup) in a request that was inspected can only be the engine's
-- deny, because every other local rejection already names its own reason.
-- CRS 949110 is the inbound blocking evaluation that issues that deny, and the
-- score recorded is the configured threshold the request reached or exceeded.
local function record_waf_outcome()
    local action = ngx.var.cdn_waf_action
    if action ~= "inspect" then return end
    local denied = tonumber(ngx.status) == 403
        and (ngx.var.upstream_status or "") == ""
        and (ngx.var.upstream_cache_status or "") == ""
        and (ngx.var.cdn_security_reason or "") == ""
    if not denied then
        ngx.var.cdn_waf_action = "pass"
        return
    end
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    local waf = config and config.waf or {}
    ngx.var.cdn_waf_action = "block"
    ngx.var.cdn_waf_rule_id = "949110"
    ngx.var.cdn_waf_score = tostring(math.max(1, math.min(100, tonumber(waf.inbound_threshold) or 5)))
    ngx.var.cdn_security_action = "block"
    ngx.var.cdn_security_reason = "waf_block"
end

function M.finish()
    record_waf_outcome()
    local client_key = ngx.var.cdn_security_client_connection_key
    if client_key and client_key ~= "" then
        local current = ngx.shared.traffic_limits:incr(client_key, -1, 0)
        if current and current <= 0 then ngx.shared.traffic_limits:delete(client_key) end
    end
    local domain_key = ngx.var.cdn_security_domain_connection_key
    if domain_key and domain_key ~= "" then
        local current = ngx.shared.domain_limits:incr(domain_key, -1, 0)
        if current and current <= 0 then ngx.shared.domain_limits:delete(domain_key) end
    end
    for _, key in ipairs({ngx.var.cdn_compression_connection_key}) do
        if key and key ~= "" then
            local current = ngx.shared.runtime_limits:incr(key, -1, 0)
            if current and current <= 0 then ngx.shared.runtime_limits:delete(key) end
        end
    end
end

function M.origin_done()
    local connection_key = ngx.var.cdn_origin_connection_key
    if not connection_key or connection_key == "" then return end
    ngx.var.cdn_origin_connection_key = ""
    local dictionary = ngx.shared.runtime_limits
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    local role = (ngx.var.cdn_origin_role ~= "" and ngx.var.cdn_origin_role) or ngx.ctx.origin_role or "primary"
    local domain = config and tostring(config.domain) or ngx.ctx.origin_domain
    ngx.shared.domain_limits:incr(connection_key, -1, 0)
    dictionary:incr("capacity:origin_connections", -1, 0)
    if not domain then return end
    local status = tonumber((ngx.var.upstream_status or ""):match("(%d+)%s*$")) or tonumber(ngx.status) or 0
    local failed = ngx.ctx.origin_failed == true or status >= 500 or status == 0
    local failover = (config and config.origin and config.origin.backup and config.origin.failover) or ngx.ctx.origin_failover
    if failover and host ~= "" then
        local prefix = "origin:failover:" .. host
        local failures_key = prefix .. ":" .. role .. ":failures"
        local recovery_key = prefix .. ":primary:recoveries"
        local ttl = math.max(10, math.min(86400, tonumber(failover.failback_delay_seconds) or 60) * 2)
        if failed then
            dictionary:delete(recovery_key)
            local failures = dictionary:incr(failures_key, 1, 0, ttl)
            if role == "primary" and failures and failures >= math.max(1, math.min(20, tonumber(failover.failure_threshold) or 3)) then
                local now = ngx.now()
                local delay = math.max(
                    math.max(5, math.min(3600, tonumber(failover.hold_down_seconds) or 30)),
                    math.max(5, math.min(86400, tonumber(failover.failback_delay_seconds) or 60))
                )
                dictionary:set(prefix .. ":active", "backup")
                dictionary:set(prefix .. ":failback_at", now + delay)
                dictionary:set(prefix .. ":reason", "primary_failure_threshold")
                dictionary:delete(failures_key)
            elseif role == "backup" then
                dictionary:set(prefix .. ":reason", "backup_failure")
            end
        else
            dictionary:delete(failures_key)
            if role == "primary" and dictionary:get(prefix .. ":active") == "backup" then
                local recoveries = dictionary:incr(recovery_key, 1, 0, ttl)
                if recoveries and recoveries >= math.max(1, math.min(20, tonumber(failover.recovery_threshold) or 2)) then
                    dictionary:set(prefix .. ":active", "primary")
                    dictionary:set(prefix .. ":reason", "primary_recovery_threshold")
                    dictionary:delete(prefix .. ":failback_at")
                    dictionary:delete(recovery_key)
                end
            elseif role == "primary" then
                dictionary:set(prefix .. ":active", "primary")
                dictionary:set(prefix .. ":reason", "none")
            end
        end
        return
    end
    -- Primary-only hosts retain their established upstream failure semantics.
    -- Their connection counter is still released above.
    return
end

function M.origin_failure()
    if ngx.var.cdn_security_reason ~= "" then
        if diagnostic_headers_enabled() then ngx.header["X-CDNF-Security-Reason"] = ngx.var.cdn_security_reason end
        return ngx.exit(503)
    end
    -- The internal 444 lets the outer cache apply stale-if-error. It must
    -- not turn a pre-connect DNS failure into a successful origin receipt.
    ngx.ctx.origin_failed = true
    return ngx.exit(444)
end

function M.passive_failures()
    local expected = os.getenv("EDGE_STATUS_TOKEN") or ""
    local supplied = ngx.req.get_headers()["x-edge-status-token"] or ""
    if expected == "" or supplied ~= expected then return ngx.exit(404) end
    local failures = {}
    for _, key in ipairs(ngx.shared.runtime_limits:get_keys(400)) do
        local host = key:match("^passive:(.+)$")
        local config = host and state.hosts[host]
        if config and #failures < 100 then
            failures[#failures + 1] = {
                domain = config.domain, hostname = host,
                failure_count = ngx.shared.runtime_limits:get(key) or 0,
                last_status = ngx.shared.runtime_limits:get("passive-status:" .. host) or 0,
                last_failed_at = ngx.shared.runtime_limits:get("passive-time:" .. host),
            }
        end
    end
    local security_events = {}
    local event_dictionary = ngx.shared.security_limits
    for _, key in ipairs(event_dictionary:get_keys(200)) do
        local domain, reason = key:match("^event:([^:]+):([%w_]+)$")
        if domain and reason and #security_events < 20 then
            security_events[#security_events + 1] = {
                domain_id = tonumber(domain), hostname = event_dictionary:get("host:" .. domain),
                reason_code = reason, count = event_dictionary:get(key) or 0,
                occurred_at = event_dictionary:get("time:" .. domain .. ":" .. reason) or ngx.time(),
            }
        end
    end
    table.sort(security_events, function(a, b) return a.count > b.count end)
    local assigned = 0
    local origins = {}
    for hostname, config in pairs(state.hosts) do
        assigned = assigned + 1
        if config.origin and config.origin.backup and #origins < 100 then
            local prefix = "origin:failover:" .. hostname
            origins[#origins + 1] = {
                hostname = hostname,
                active = ngx.shared.runtime_limits:get(prefix .. ":active") or "primary",
                reason = ngx.shared.runtime_limits:get(prefix .. ":reason") or "none",
                failback_at = ngx.shared.runtime_limits:get(prefix .. ":failback_at"),
            }
        end
    end
    local memory_usage = 0
    local memory_file = io.open("/sys/fs/cgroup/memory.current", "r")
    if memory_file then
        memory_usage = tonumber(memory_file:read("*l")) or 0
        memory_file:close()
    end
    local memory_limit = nil
    local memory_limit_file = io.open("/sys/fs/cgroup/memory.max", "r")
    if memory_limit_file then
        local raw_limit = memory_limit_file:read("*l")
        if raw_limit ~= "max" then memory_limit = tonumber(raw_limit) end
        memory_limit_file:close()
    end
    local cpu_usage_usec = 0
    local cpu_file = io.open("/sys/fs/cgroup/cpu.stat", "r")
    if cpu_file then
        for line in cpu_file:lines() do
            local usage = line:match("^usage_usec%s+(%d+)$")
            if usage then cpu_usage_usec = tonumber(usage) or 0; break end
        end
        cpu_file:close()
    end
    local cache_free = ngx.shared.runtime_limits:free_space()
    local now = ngx.time()
    local previous_cpu_usage = ngx.shared.runtime_limits:get("capacity:cpu_usage_usec")
    local previous_cpu_time = ngx.shared.runtime_limits:get("capacity:cpu_sample_time")
    local cpu_usage = 0
    if previous_cpu_usage and previous_cpu_time and now > previous_cpu_time and cpu_usage_usec >= previous_cpu_usage then
        cpu_usage = ((cpu_usage_usec - previous_cpu_usage) / ((now - previous_cpu_time) * 1000000)) * 100
    end
    ngx.shared.runtime_limits:set("capacity:cpu_usage_usec", cpu_usage_usec)
    ngx.shared.runtime_limits:set("capacity:cpu_sample_time", now)
    local requests_per_second = (ngx.shared.runtime_limits:get("capacity:requests:" .. now) or 0)
        + (ngx.shared.runtime_limits:get("capacity:requests:" .. (now - 1)) or 0)
    ngx.header["Content-Type"] = "application/json"
    local drained = ngx.shared.runtime_limits:get("control:drained") == true
    ngx.say(cjson.encode({
        data = #failures == 0 and cjson.empty_array or failures,
        origins = #origins == 0 and cjson.empty_array or origins,
        security = #security_events == 0 and cjson.empty_array or security_events,
        cell = {
            name = os.getenv("EDGE_CELL_NAME") or "unknown",
            status = drained and "drained" or "ready",
            purges_restored = ngx.shared.cache_purges:get("purge:restored") == true,
            overlays_restored = ngx.shared.runtime_limits:get("overlay:restored") == true,
			capacity = {
				assigned_domain_count = assigned,
				active_revision = state.sequence,
				active_generation = state.generation_id,
                openresty_version = ngx.config.nginx_version,
                active_connections = tonumber(ngx.var.connections_active) or 0,
                requests_per_second = requests_per_second,
                origin_connections = ngx.shared.runtime_limits:get("capacity:origin_connections") or 0,
                cpu_usage = cpu_usage,
                memory_usage = memory_usage,
                memory_limit = memory_limit or cjson.null,
                cache_usage = 10 * 1024 * 1024 - cache_free,
                cache_limit = 10 * 1024 * 1024,
                cache_free_bytes = cache_free,
                connection_limit = 4096,
                temporary_storage_usage = cjson.null,
                telemetry_buffer_usage = cjson.null,
                rejected_requests = ngx.shared.runtime_limits:get("capacity:rejected_requests") or 0,
                last_restart_at = ngx.shared.runtime_limits:get("control:last_restart_at"),
            },
        },
    }))
end

function M.control()
    local expected = os.getenv("EDGE_STATUS_TOKEN") or ""
    local supplied = ngx.req.get_headers()["x-edge-status-token"] or ""
    if expected == "" or supplied ~= expected then return ngx.exit(404) end
    if ngx.req.get_method() ~= "POST" then return ngx.exit(405) end
    ngx.req.read_body()
    local raw = ngx.req.get_body_data() or ""
    if #raw == 0 or #raw > 128 * 1024 then return ngx.exit(400) end
    local command = cjson.decode(raw)
    if type(command) ~= "table" or type(command.task_id) ~= "string" or #command.task_id > 64
        or (command.action ~= "drain" and command.action ~= "undrain" and command.action ~= "restart" and command.action ~= "cache_purge" and command.action ~= "cache_purges_restored" and command.action ~= "emergency_mode"
            and command.action ~= "security_overlay" and command.action ~= "security_overlays_restored") then
        return ngx.exit(400)
    end
    if command.action == "emergency_mode" then
        if type(command.active) ~= "boolean" or type(command.actions) ~= "table" or #command.actions > 12
            or (command.expires_at ~= nil and (type(command.expires_at) ~= "number" or command.expires_at <= ngx.time())) then return ngx.exit(400) end
        -- Only actions the cell enforces are accepted; the others are rejected, never ignored.
        local supported = {disable_request_bodies=true,allow_get_head_only=true,disable_origin_retries=true,
            serve_cache_only=true,serve_stale_only=true,return_maintenance_response=true,disable_compression=true}
        for _, action in ipairs(command.actions) do if not supported[action] then return ngx.exit(400) end end
    end
    if command.action == "security_overlay" then
        local states = { normal = true, suspected = true, restricted = true, recovering = true }
        if type(command.domain_id) ~= "number" or command.domain_id < 1 or command.domain_id % 1 ~= 0
            or type(command.sequence) ~= "number" or command.sequence < 1 or command.sequence % 1 ~= 0
            or not states[command.state] then return ngx.exit(400) end
        if command.limits ~= nil and command.limits ~= cjson.null then
            if type(command.limits) ~= "table" then return ngx.exit(400) end
            local count = 0
            for name, value in pairs(command.limits) do
                count = count + 1
                if type(name) ~= "string" or #name > 64 or type(value) ~= "number" or value < 0 or count > 64 then return ngx.exit(400) end
            end
        end
        local incident = command.incident
        if incident ~= nil and incident ~= cjson.null then
            if type(incident) ~= "table" or type(incident.expires_at) ~= "number" or incident.expires_at % 1 ~= 0
                or type(incident.waf) ~= "table" or incident.waf.mode ~= "block" or type(incident.methods) ~= "table" or #incident.methods > 8
                or type(incident.max_body_bytes) ~= "number" or incident.max_body_bytes < 0 or type(incident.challenge) ~= "boolean" then return ngx.exit(400) end
            local known = { GET = true, HEAD = true, OPTIONS = true, POST = true, PUT = true, PATCH = true, DELETE = true }
            for _, name in ipairs(incident.methods) do if not known[name] then return ngx.exit(400) end end
            if incident.challenge_bypass_prefixes ~= nil then
                if type(incident.challenge_bypass_prefixes) ~= "table" or #incident.challenge_bypass_prefixes > 8 then return ngx.exit(400) end
                for _, prefix in ipairs(incident.challenge_bypass_prefixes) do
                    if type(prefix) ~= "string" or #prefix == 0 or #prefix > 128 or prefix:sub(1, 1) ~= "/" then return ngx.exit(400) end
                end
            end
        end
    end
    if command.action == "cache_purge" then
        if type(command.domain) ~= "string" or #command.domain == 0 or #command.domain > 253
            or (command.type ~= "all" and command.type ~= "urls")
            or type(command.cache_epoch) ~= "number" or command.cache_epoch < 1
            or type(command.purge_id) ~= "number" or command.purge_id < 1 or command.purge_id % 1 ~= 0
            or type(command.cache_keys) ~= "table" or #command.cache_keys > 100
            or (command.type == "all" and #command.cache_keys ~= 0)
            or (command.type == "urls" and #command.cache_keys == 0) then
            return ngx.exit(400)
        end
        for _, key in ipairs(command.cache_keys) do
            if type(key) ~= "string" or #key == 0 or #key > 4096 then return ngx.exit(400) end
        end
    end
    local dictionary = ngx.shared.runtime_limits
    local replayed = dictionary:get("control:last_task_id") == command.task_id
    if not replayed then
        if command.action == "drain" then
            dictionary:set("control:drained", true)
        elseif command.action == "undrain" then
            dictionary:delete("control:drained")
            dictionary:delete("control:restart_resume_at")
        elseif command.action == "restart" then
            local already_drained = dictionary:get("control:drained") == true
            dictionary:set("control:drained", true)
            if not already_drained then dictionary:set("control:restart_resume_at", ngx.now() + 2) end
            dictionary:set("control:last_restart_at", ngx.time())
            dictionary:incr("control:restart_generation", 1, 0)
        elseif command.action == "cache_purges_restored" then
            ngx.shared.cache_purges:set("purge:restored", true)
        elseif command.action == "security_overlays_restored" then
            dictionary:set("overlay:restored", true)
        elseif command.action == "security_overlay" then
            -- A lower sequence is an obsolete message; a normal state removes the overlay.
            local key = "overlay:" .. tostring(command.domain_id)
            local current = cjson.decode(dictionary:get(key) or "null")
            if type(current) ~= "table" or command.sequence >= (tonumber(current.sequence) or 0) then
                if command.state == "normal" then
                    dictionary:delete(key)
                else
                    local limits = (type(command.limits) == "table") and command.limits or nil
                    local incident = (type(command.incident) == "table") and command.incident or nil
                    local ok, err = dictionary:safe_set(key, cjson.encode({ sequence = command.sequence, state = command.state, limits = limits, incident = incident }))
                    if not ok then ngx.log(ngx.ERR, "security overlay not stored: ", err); return ngx.exit(507) end
                end
            end
        elseif command.action == "emergency_mode" then
            if command.active then
                dictionary:set("emergency:active", cjson.encode(command.actions))
                if command.expires_at then dictionary:set("emergency:expires_at", command.expires_at) else dictionary:delete("emergency:expires_at") end
            else
                dictionary:delete("emergency:active")
                dictionary:delete("emergency:expires_at")
            end
        elseif command.action == "cache_purge" and command.type == "all" then
            local epoch_key = "cache:epoch:" .. command.domain
            local current = dictionary:get(epoch_key) or 0
            if command.cache_epoch > current then dictionary:set(epoch_key, command.cache_epoch) end
        else
            -- Purge generations live in their own dictionary so rate-limit counters can
            -- never evict them, and are set to an absolute purge id so the agent can
            -- replay them after a restart without double counting. A full dictionary
            -- is reported, never silently evicted.
            local purges = ngx.shared.cache_purges
            for _, key in ipairs(command.cache_keys) do
                local name = "u:" .. ngx.md5(key)
                local current = purges:get(name) or 0
                if command.purge_id > current then
                    local ok = purges:safe_set(name, command.purge_id, PURGE_TTL_SECONDS)
                    if not ok then return ngx.exit(507) end
                end
            end
        end
        dictionary:set("control:last_task_id", command.task_id)
    end
    ngx.header["Content-Type"] = "application/json"
    ngx.say(cjson.encode({data = {accepted = true, replayed = replayed, action = command.action, applied_keys = command.cache_keys and #command.cache_keys or 0}}))
end

return M
