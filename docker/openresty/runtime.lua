local cjson = require "cjson.safe"
local resolver = require "resty.dns.resolver"
local balancer = require "ngx.balancer"
local bit = require "bit"
local ffi = require "ffi"
ffi.cdef[[int kill(int pid, int sig);]]
local M = {}
local state = { hosts = {}, certificates = {}, sequence = 0 }
local path = os.getenv("EDGE_RUNTIME_FILE") or "/var/lib/cdnfoundry/runtime/active.json"
local restart_generation = 0
local certificate_cache = {}
local certificate_cache_order = {}
local certificate_cache_limit = 512
local cache_control_directives
local geo_database

local function security_reject(status, reason)
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("capacity:rejected_requests", 1, 0)
    dictionary:incr("security:reason:" .. reason, 1, 0)
    if ngx.ctx.security_domain then
        local events = ngx.shared.security_limits
        local key = "event:" .. tostring(ngx.ctx.security_domain) .. ":" .. reason
        events:incr(key, 1, 0)
        events:set("host:" .. tostring(ngx.ctx.security_domain), ngx.ctx.security_hostname or "", 3600)
        events:set("time:" .. tostring(ngx.ctx.security_domain) .. ":" .. reason, ngx.time(), 3600)
    end
    ngx.header["X-CDNFoundry-Security-Reason"] = reason
    ngx.var.cdn_security_reason = reason
    ngx.var.cdn_security_action = "block"
    return ngx.exit(status)
end

local function ipv4_number(ip)
    local a,b,c,d = ip:match("^(%d+)%.(%d+)%.(%d+)%.(%d+)$")
    if not a then return nil end
    return tonumber(a)*16777216 + tonumber(b)*65536 + tonumber(c)*256 + tonumber(d)
end

local function allowed(ip, networks)
    local value = ipv4_number(ip)
    for _, cidr in ipairs(networks or {}) do
        local network, bits = cidr:match("^([^/]+)/(%d+)$")
        local base = network and ipv4_number(network)
        bits = tonumber(bits)
        if value and base and bits and bits >= 0 and bits <= 32 then
            local size = 2 ^ (32 - bits)
            if math.floor(value / size) == math.floor(base / size) then return true end
        end
        if not value and network and bits and bits >= 0 and bits <= 128 then
            local function ipv6_bytes(address)
                address = address:lower():gsub("^%[", ""):gsub("%]$", "")
                if address:find("%.") then return nil end
                local left, right = address:match("^(.-)::(.-)$")
                local groups = {}
                local function append(part)
                    if part == "" then return true end
                    for group in part:gmatch("[^:]+") do
                        local number = tonumber(group, 16)
                        if not number or number > 65535 then return false end
                        groups[#groups + 1] = number
                    end
                    return true
                end
                if left then
                    if not append(left) then return nil end
                    local left_count = #groups
                    local tail = {}
                    for group in right:gmatch("[^:]+") do
                        local number = tonumber(group, 16)
                        if not number or number > 65535 then return nil end
                        tail[#tail + 1] = number
                    end
                    local missing = 8 - left_count - #tail
                    if missing < 1 then return nil end
                    for _ = 1, missing do groups[#groups + 1] = 0 end
                    for _, number in ipairs(tail) do groups[#groups + 1] = number end
                elseif not append(address) or #groups ~= 8 then
                    return nil
                end
                if #groups ~= 8 then return nil end
                local bytes = {}
                for _, number in ipairs(groups) do
                    bytes[#bytes + 1] = math.floor(number / 256)
                    bytes[#bytes + 1] = number % 256
                end
                return bytes
            end
            local address_bytes, network_bytes = ipv6_bytes(ip), ipv6_bytes(network)
            if address_bytes and network_bytes then
                local whole, remainder = math.floor(bits / 8), bits % 8
                local matches = true
                for index = 1, whole do
                    if address_bytes[index] ~= network_bytes[index] then matches = false; break end
                end
                if matches and remainder > 0 then
                    local mask = bit.band(0xff, bit.lshift(0xff, 8 - remainder))
                    matches = bit.band(address_bytes[whole + 1], mask) == bit.band(network_bytes[whole + 1], mask)
                end
                if matches then return true end
            end
        end
    end
    return false
end

local function blocked(ip, networks, blocked_networks, denied)
    for _, address in ipairs(denied or {}) do
        if ip == address then return true end
    end
    if allowed(ip, blocked_networks) then return true end
    if ip:lower():match("^::ffff:") then return true end
    if ip == "0.0.0.0" or ip:match("^127%.") or ip:match("^169%.254%.") or ip:match("^224%.") then return true end
    local a, b = ip:match("^(%d+)%.(%d+)%.")
    a, b = tonumber(a), tonumber(b)
    if (a == 10 or a == 192 and b == 168 or a == 172 and b and b >= 16 and b <= 31) and not allowed(ip, networks) then return true end
    if a == 100 and b and b >= 64 and b <= 127 then return true end
    if a == 192 and b == 0 or a == 198 and b and (b == 18 or b == 19) then return true end
    local lower = ip:lower()
    local hard_v6 = lower == "::" or lower == "::1" or lower:match("^fe[89ab]") ~= nil
        or lower:match("^fe[c-f]") ~= nil or lower:match("^ff") ~= nil
        or lower:match("^64:ff9b:") ~= nil
    if hard_v6 then return true end
    local private_v6 = lower:match("^f[cd]") ~= nil
    if private_v6 and allowed(ip, networks) then return false end
    return private_v6
end

local function load()
    local file = io.open(path, "rb")
    if not file then return nil, "runtime state unavailable" end
    local raw = file:read("*a"); file:close()
    if #raw > 64 * 1024 * 1024 then return nil, "runtime state exceeds limit" end
    local decoded = cjson.decode(raw)
    if not decoded or decoded.schema_version ~= 1 or type(decoded.hosts) ~= "table" then return nil, "invalid runtime state" end
    if decoded.certificates == nil then decoded.certificates = {} end
    if type(decoded.certificates) ~= "table" then return nil, "invalid runtime certificates" end
    if decoded.sequence ~= state.sequence then
        certificate_cache = {}
        certificate_cache_order = {}
    end
    state = decoded
    return true
end

local function refresh(premature)
    if premature then return end
    local ok, err = load()
    if not ok then ngx.log(ngx.WARN, err) end
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

function M.select_certificate()
    local ssl = require "ngx.ssl"
    local name = ssl.server_name()
    name = name and name:lower():gsub("%.$", "") or ""
    local config = state.hosts[name]
    if not config then
        ngx.shared.runtime_limits:incr("security:reason:unknown_sni", 1, 0)
        error("unknown TLS SNI")
    end
    local security = config.security or {}
    local limits = security.limits or {}
    local second = ngx.time()
    local handshakes = ngx.shared.runtime_limits:incr("security:tls:" .. tostring(config.domain) .. ":" .. second, 1, 0, 2)
    if handshakes and handshakes > (tonumber(limits.tls_handshakes_per_second) or 50) then
        error("tls_handshake_rate_exceeded")
    end
    if not config.tls or config.tls.mode == "disabled" or not config.tls.certificate_id then error("TLS disabled or certificate unavailable") end
    local certificate = state.certificates[config.tls.certificate_id]
    if not certificate then error("TLS certificate unavailable") end
    if tonumber(certificate.expires_at) and tonumber(certificate.expires_at) <= ngx.time() then error("TLS certificate expired") end
    local cached = certificate_cache[certificate.id]
    if not cached then
        local cert_der, cert_err = ssl.cert_pem_to_der((certificate.certificate_pem or "") .. (certificate.chain_pem or ""))
        local key_der, key_err = ssl.priv_key_pem_to_der(certificate.private_key_pem or "")
        if not cert_der or not key_der then error("invalid TLS bundle: " .. (cert_err or key_err or "unknown")) end
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

local function resolve(host, networks, blocked_networks, denied)
    if host:match("^%d+%.%d+%.%d+%.%d+$") or host:find(":", 1, true) then
        if blocked(host, networks, blocked_networks, denied) then return nil, "blocked_destination" end
        return host
    end
    local r, err = resolver:new{nameservers={"127.0.0.11"}, retrans=2, timeout=1000}
    if not r then return nil, err end
    for _, qtype in ipairs({r.TYPE_A, r.TYPE_AAAA}) do
        local answers = r:query(host, {qtype=qtype})
        if answers then
            for _, answer in ipairs(answers) do
                if answer.address then
                    if blocked(answer.address, networks, blocked_networks, denied) then return nil, "blocked_destination" end
                    return answer.address
                end
            end
        end
    end
    return nil, "dns_resolution_failed"
end

local function reject(status)
    return security_reject(status, "malformed_request")
end

local function client_address(security)
    local remote = ngx.var.remote_addr or ""
    if allowed(remote, security.trusted_proxy_cidrs) then
        local forwarded = (ngx.var.http_x_forwarded_for or ""):match("^%s*([^,%s]+)")
        if forwarded and (#forwarded <= 45) and (ipv4_number(forwarded) or forwarded:find(":", 1, true)) then
            return forwarded
        end
    end
    return remote
end

local function geography(ip)
    if not geo_database then return "unknown", "unknown" end
    local ok, result = pcall(geo_database.lookup, geo_database, ip)
    if not ok or not result then return "unknown", "unknown" end
    local ok_country, country = pcall(result.get, result, "country", "iso_code")
    local ok_continent, continent = pcall(result.get, result, "continent", "code")
    return ok_country and country or "unknown", ok_continent and continent or "unknown"
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

local function request_cache_key(domain, host, cache)
    local request_uri = ngx.var.request_uri or "/"
    if cache.include_query_string == false then request_uri = request_uri:match("^([^?]*)") or "/" end
    local base = (ngx.var.scheme or "http") .. "|" .. host .. "|" .. request_uri
    local dictionary = ngx.shared.runtime_limits
    local configured_epoch = tonumber(cache.epoch) or 1
    local runtime_epoch = tonumber(dictionary:get("cache:epoch:" .. domain)) or 0
    local epoch = math.max(configured_epoch, runtime_epoch)
    local generation = dictionary:get("cache:url:" .. ngx.md5(base)) or 0
    local ttl = math.max(0, math.min(31536000, tonumber(cache.edge_ttl_seconds) or 0))
    local policy = table.concat({
        tostring(ttl), tostring(math.max(0, math.min(31536000, tonumber(cache.browser_ttl_seconds) or 0))),
        tostring(math.max(1024, math.min(1073741824, tonumber(cache.maximum_object_bytes) or 104857600))),
        cache.respect_origin_headers == false and "0" or "1",
        tostring(math.max(0, math.min(86400, tonumber(cache.stale_if_error_seconds) or 0))),
    }, ":")
    return base .. "|e" .. epoch .. "|g" .. generation .. "|p" .. ngx.md5(policy), ttl
end

local function cookie_bypassed(names)
    local raw = ngx.var.http_cookie or ""
    if raw == "" then return false end
    local present = {}
    for name in raw:gmatch("%s*([^=;%s]+)%s*=") do present[name] = true end
    for _, name in ipairs(names or {}) do if present[name] then return true end end
    return false
end

function M.access()
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("capacity:requests:" .. ngx.time(), 1, 0, 2)
    if dictionary:get("control:drained") == true then return security_reject(503, "edge_emergency_mode") end
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then return security_reject(421, "unknown_host") end
    ngx.var.cdn_original_host = host
    ngx.var.cdn_domain_id = tostring(config.domain_id or 0)
    ngx.var.cdn_edge_id = os.getenv("EDGE_CELL_NAME") or "unknown"
    ngx.ctx.security_domain = config.domain_id or config.domain
    ngx.ctx.security_hostname = host
    if config.settings and config.settings.enabled == false then return reject(503) end
    local security = config.security or {}
    local limits = security.limits or {}
    local emergency = emergency_actions(dictionary)
    if emergency.return_maintenance_response then return security_reject(503, "edge_emergency_mode") end
    if security.state == "quarantined" then return security_reject(429, "domain_quarantined") end
    local method = ngx.req.get_method()
    if emergency.allow_get_head_only and method ~= "GET" and method ~= "HEAD" then return security_reject(405, "edge_emergency_mode") end
    local method_allowed = false
    for _, allowed_method in ipairs(security.allowed_methods or {"GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"}) do
        if method == allowed_method then method_allowed = true; break end
    end
    if not method_allowed then return security_reject(405, "invalid_method") end
    if request_header_size() > (tonumber(limits.maximum_header_size) or 32768) then return security_reject(431, "header_too_large") end
    local size = body_size()
    if emergency.disable_request_bodies and size > 0 then return security_reject(413, "edge_emergency_mode") end
    if size > (tonumber(limits.maximum_request_body_size) or 16777216) then return security_reject(413, "body_too_large") end
    local client = client_address(security)
    local country, continent = geography(client)
    ngx.var.cdn_client_ip = client
    ngx.var.cdn_country = country or "ZZ"
    ngx.var.cdn_continent = continent or "ZZ"
    for _, rule in ipairs(security.rules or {}) do
        if rule_matches(rule, client, country, continent) then
            if rule.action == "block" then return security_reject(403, "domain_restricted") end
            break
        end
    end
    local second = ngx.time()
    local client_key = "security:req:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client) .. ":" .. second
    local domain_key = "security:req:domain:" .. tostring(config.domain) .. ":" .. second
    local client_requests = dictionary:incr(client_key, 1, 0, 2)
    local domain_requests = dictionary:incr(domain_key, 1, 0, 2)
    local rps = tonumber(limits.requests_per_second) or 100
    local burst = tonumber(limits.request_burst) or 200
    if client_requests and client_requests > rps + burst then return security_reject(429, "client_rate_exceeded") end
    if domain_requests and domain_requests > rps * 8 + burst then return security_reject(429, "domain_rate_exceeded") end
    local client_connections = dictionary:incr("security:conn:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client), 1, 0)
    local domain_connections = dictionary:incr("security:conn:domain:" .. tostring(config.domain), 1, 0)
    if client_connections and client_connections > (tonumber(limits.connections_per_client) or 64) then
        dictionary:incr("security:conn:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client), -1, 0)
        dictionary:incr("security:conn:domain:" .. tostring(config.domain), -1, 0)
        return security_reject(429, "client_connections_exceeded")
    end
    if domain_connections and domain_connections > (tonumber(limits.connections_per_domain) or 512) then
        dictionary:incr("security:conn:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client), -1, 0)
        dictionary:incr("security:conn:domain:" .. tostring(config.domain), -1, 0)
        return security_reject(429, "domain_connections_exceeded")
    end
    ngx.ctx.security_connection_keys = {"security:conn:client:" .. tostring(config.domain) .. ":" .. ngx.md5(client), "security:conn:domain:" .. tostring(config.domain)}
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
    if config.settings and config.settings.maintenance then
        ngx.status = 503; ngx.header["Content-Type"] = "text/plain"; ngx.say(config.settings.maintenance.body or "Service unavailable"); return ngx.exit(503)
    end
    local cache = config.cache or {}
    local cache_key, cache_ttl = request_cache_key(config.domain, host, cache)
    local development_until = tonumber(cache.development_mode_until) or 0
    local cacheable = cache.enabled == true and cache_ttl > 0
        and (ngx.req.get_method() == "GET" or ngx.req.get_method() == "HEAD")
        and not ngx.var.http_authorization and not ngx.var.http_range
        and not cookie_bypassed(cache.bypass_cookie_names)
        and development_until <= ngx.time()
        and #cache_key <= (tonumber(limits.maximum_cache_key_length) or 4096)
    local admissions = dictionary:incr("security:cache:" .. tostring(config.domain) .. ":" .. second, 1, 0, 2)
    if admissions and admissions > (tonumber(limits.cache_admissions_per_second) or 50) then cacheable = false end
    if #cache_key > (tonumber(limits.maximum_cache_key_length) or 4096) then ngx.var.cdn_security_reason = "cache_abuse_detected" end
    ngx.var.cdn_cache_key = cache_key
    ngx.var.cdn_cache_bypass = cacheable and "0" or "1"
    ngx.var.cdn_cache_no_store = "0"
    ngx.var.cdn_cache_edge_ttl = tostring(cache_ttl)
    ngx.var.cdn_cache_browser_ttl = tostring(math.max(0, math.min(31536000, tonumber(cache.browser_ttl_seconds) or 0)))
    ngx.var.cdn_cache_max_object = tostring(math.max(1024, math.min(1073741824, tonumber(cache.maximum_object_bytes) or 104857600)))
    ngx.var.cdn_cache_stale = tostring(math.max(0, math.min(86400, tonumber(cache.stale_if_error_seconds) or 0)))
    ngx.var.cdn_cache_respect_origin = cache.respect_origin_headers == false and "0" or "1"
    local accept_encoding = (ngx.var.http_accept_encoding or ""):lower()
    if accept_encoding:find("gzip", 1, true) then ngx.req.set_header("Accept-Encoding", "gzip") else ngx.req.clear_header("Accept-Encoding") end
    local maximum = tonumber(cache.maximum_object_bytes) or 104857600
    if maximum <= 1048576 then return ngx.exec("@cache_1m") end
    if maximum <= 10485760 then return ngx.exec("@cache_10m") end
    return ngx.exec("@cache_100m")
end

function M.invalid_method()
    return security_reject(405, ngx.var.cdn_security_reason ~= "" and ngx.var.cdn_security_reason or "invalid_method")
end

function M.origin_access()
    local dictionary = ngx.shared.runtime_limits
    local host = (ngx.var.cdn_original_host ~= "" and ngx.var.cdn_original_host or ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then return security_reject(421, "unknown_host") end
    ngx.ctx.security_domain = config.domain_id or config.domain
    ngx.ctx.security_hostname = host
    local security = config.security or {}
    local limits = security.limits or {}
    local emergency = emergency_actions(dictionary)
    if emergency.serve_cache_only or emergency.serve_stale_only then return security_reject(503, "edge_emergency_mode") end
    local circuit_key = "security:origin:open:" .. tostring(config.domain)
    local open_until = tonumber(dictionary:get(circuit_key)) or 0
    if open_until > ngx.now() then return security_reject(503, "origin_circuit_open") end
    local origin_key = "security:origin:connections:" .. tostring(config.domain)
    local active = dictionary:incr(origin_key, 1, 0)
    if active and active > (tonumber(limits.origin_max_connections) or 128) then
        dictionary:incr(origin_key, -1, 0)
        return security_reject(503, "origin_capacity_exceeded")
    end
    ngx.ctx.origin_connection_key = origin_key
    ngx.ctx.origin_domain = tostring(config.domain)
    ngx.ctx.origin_failure_threshold = tonumber(limits.origin_failure_threshold) or 10
    ngx.ctx.origin_recovery_timeout = tonumber(limits.origin_recovery_timeout) or 30
    dictionary:incr("capacity:origin_connections", 1, 0)
    local origin = config.origin
    local address, err = resolve(origin.host, origin.private_allowlist, origin.blocked_networks, origin.blocked_addresses)
    if not address then
        ngx.log(ngx.WARN, "origin rejected: ", err)
        ngx.header["X-CDNFoundry-Error"] = err
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
    ngx.var.origin_address = address:gsub("^%[", ""):gsub("%]$", "")
    ngx.var.origin_connect_timeout = tostring(math.min((tonumber(limits.origin_connect_timeout) or 3) * 1000, math.max(100, math.min(10000, tonumber(origin.connect_timeout_ms) or 1000))))
    ngx.var.origin_response_timeout = tostring(math.min((tonumber(limits.origin_read_timeout) or 30) * 1000, math.max(500, math.min(60000, tonumber(origin.response_timeout_ms) or 5000))))
    local retry_limit = emergency.disable_origin_retries and 0 or (tonumber(limits.origin_retry_limit) or 0)
    ngx.var.origin_retry_count = tostring(math.max(0, math.min(retry_limit, tonumber(origin.retry_count) or tonumber(config.settings and config.settings.retry_count) or 0)))
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
    local cache_control = cache_control_directives(headers["Cache-Control"])
    local no_store = status ~= 200 or headers["Set-Cookie"] ~= nil
        or cache_control["private"] ~= nil or cache_control["no-store"] ~= nil or cache_control["no-cache"] ~= nil
    local vary = headers["Vary"]
    if vary then
        local tokens, count = {}, 0
        for token in tostring(vary):lower():gmatch("[^,%s]+") do tokens[token] = true; count = count + 1 end
        if #tostring(vary) > 256 or count > 4 or tokens["*"] or (count > 0 and not (count == 1 and tokens["accept-encoding"])) then no_store = true end
    end
    local maximum = math.max(1048576, math.min(104857600, tonumber(cache.maximum_object_bytes) or 104857600))
    local length = tonumber(headers["Content-Length"])
    if length and length > maximum then no_store = true end
    if no_store then
        headers["X-CDNFoundry-No-Store"] = "1"
        return
    end
    local edge_ttl = math.max(0, math.min(31536000, tonumber(cache.edge_ttl_seconds) or 0))
    if cache.respect_origin_headers ~= false then
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
        headers["X-CDNFoundry-No-Store"] = "1"
        return
    end
    local browser = math.max(0, math.min(31536000, tonumber(cache.browser_ttl_seconds) or 0))
    local stale = math.max(0, math.min(86400, tonumber(cache.stale_if_error_seconds) or 0))
    headers["Cache-Control"] = "public, s-maxage=" .. edge_ttl .. ", max-age=" .. browser .. (stale > 0 and ", stale-if-error=" .. stale or "")
end

function M.cache_status()
    local no_store = ngx.var.upstream_http_x_cdnfoundry_no_store == "1"
    ngx.header["X-CDNFoundry-Cache"] = (ngx.var.cdn_cache_bypass == "1" or no_store)
        and "BYPASS" or (ngx.var.upstream_cache_status or "MISS")
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
    local retry_count = tonumber(ngx.var.origin_retry_count) or 0
    balancer.set_timeouts(connect_timeout / 1000, response_timeout / 1000, response_timeout / 1000)
    local keepalive_ok, keepalive_err = balancer.enable_keepalive(30, 1000)
    if not keepalive_ok then error("unable to enable origin keepalive: " .. (keepalive_err or "unknown")) end
    if retry_count > 0 then balancer.set_more_tries(retry_count) end
end

function M.record_passive_failure()
    local status = tonumber((ngx.var.upstream_status or ""):match("%d+"))
    if status and status < 500 then return end
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then return end
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("passive:" .. host, 1, 0)
    dictionary:set("passive-status:" .. host, status or 0)
    dictionary:set("passive-time:" .. host, ngx.time())
end

function M.finish()
    local keys = ngx.ctx.security_connection_keys
    if not keys then return end
    for _, key in ipairs(keys) do
        local current = ngx.shared.runtime_limits:incr(key, -1, 0)
        if current and current <= 0 then ngx.shared.runtime_limits:delete(key) end
    end
end

function M.origin_done()
    local dictionary = ngx.shared.runtime_limits
    if ngx.ctx.origin_connection_key then
        local current = dictionary:incr(ngx.ctx.origin_connection_key, -1, 0)
        if current and current <= 0 then dictionary:delete(ngx.ctx.origin_connection_key) end
        dictionary:incr("capacity:origin_connections", -1, 0)
    end
    local domain = ngx.ctx.origin_domain
    if not domain then return end
    local status = tonumber((ngx.var.upstream_status or ""):match("%d+")) or tonumber(ngx.status) or 0
    local failure_key = "security:origin:failures:" .. domain
    if status >= 500 or status == 0 then
        local failures = dictionary:incr(failure_key, 1, 0, math.max(2, ngx.ctx.origin_recovery_timeout or 30))
        if failures and failures >= (ngx.ctx.origin_failure_threshold or 10) then
            dictionary:set("security:origin:open:" .. domain, ngx.now() + (ngx.ctx.origin_recovery_timeout or 30), ngx.ctx.origin_recovery_timeout or 30)
        end
    else
        dictionary:delete(failure_key)
        dictionary:delete("security:origin:open:" .. domain)
    end
end

function M.origin_failure()
    if ngx.var.cdn_security_reason ~= "" then
        ngx.header["X-CDNFoundry-Security-Reason"] = ngx.var.cdn_security_reason
        return ngx.exit(503)
    end
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
    for _ in pairs(state.hosts) do assigned = assigned + 1 end
    local memory_usage = 0
    local memory_file = io.open("/sys/fs/cgroup/memory.current", "r")
    if memory_file then
        memory_usage = tonumber(memory_file:read("*l")) or 0
        memory_file:close()
    end
    local cpu_usage = 0
    local cpu_file = io.open("/sys/fs/cgroup/cpu.stat", "r")
    if cpu_file then
        for line in cpu_file:lines() do
            local usage = line:match("^usage_usec%s+(%d+)$")
            if usage then cpu_usage = tonumber(usage) or 0; break end
        end
        cpu_file:close()
    end
    local cache_free = ngx.shared.runtime_limits:free_space()
    local now = ngx.time()
    local requests_per_second = (ngx.shared.runtime_limits:get("capacity:requests:" .. now) or 0)
        + (ngx.shared.runtime_limits:get("capacity:requests:" .. (now - 1)) or 0)
    ngx.header["Content-Type"] = "application/json"
    local drained = ngx.shared.runtime_limits:get("control:drained") == true
    ngx.say(cjson.encode({
        data = #failures == 0 and cjson.empty_array or failures,
        security = #security_events == 0 and cjson.empty_array or security_events,
        cell = {
            name = os.getenv("EDGE_CELL_NAME") or "unknown",
            status = drained and "drained" or "ready",
            capacity = {
                assigned_domain_count = assigned,
                active_revision = state.sequence,
                openresty_version = ngx.config.nginx_version,
                active_connections = tonumber(ngx.var.connections_active) or 0,
                requests_per_second = requests_per_second,
                origin_connections = ngx.shared.runtime_limits:get("capacity:origin_connections") or 0,
                cpu_usage = cpu_usage,
                memory_usage = memory_usage,
                cache_usage = 10 * 1024 * 1024 - cache_free,
                cache_free_bytes = cache_free,
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
        or (command.action ~= "drain" and command.action ~= "undrain" and command.action ~= "restart" and command.action ~= "cache_purge" and command.action ~= "emergency_mode") then
        return ngx.exit(400)
    end
    if command.action == "emergency_mode" then
        if type(command.active) ~= "boolean" or type(command.actions) ~= "table" or #command.actions > 11
            or (command.expires_at ~= nil and (type(command.expires_at) ~= "number" or command.expires_at <= ngx.time())) then return ngx.exit(400) end
        local supported = {reject_unknown_hosts=true,disable_request_bodies=true,allow_get_head_only=true,reduce_keepalive=true,
            reduce_origin_concurrency=true,disable_origin_retries=true,serve_cache_only=true,serve_stale_only=true,
            return_maintenance_response=true,quarantine_domain=true,withdraw_service_ip_from_dns=true}
        for _, action in ipairs(command.actions) do if not supported[action] then return ngx.exit(400) end end
    end
    if command.action == "cache_purge" then
        if type(command.domain) ~= "string" or #command.domain == 0 or #command.domain > 253
            or (command.type ~= "all" and command.type ~= "urls")
            or type(command.cache_epoch) ~= "number" or command.cache_epoch < 1
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
        elseif command.action == "emergency_mode" then
            if command.active then
                dictionary:set("emergency:active", cjson.encode(command.actions))
                if command.expires_at then dictionary:set("emergency:expires_at", command.expires_at) else dictionary:delete("emergency:expires_at") end
            else
                dictionary:delete("emergency:active")
                dictionary:delete("emergency:expires_at")
            end
        elseif command.type == "all" then
            local epoch_key = "cache:epoch:" .. command.domain
            local current = dictionary:get(epoch_key) or 0
            if command.cache_epoch > current then dictionary:set(epoch_key, command.cache_epoch) end
        else
            for _, key in ipairs(command.cache_keys) do
                dictionary:incr("cache:url:" .. ngx.md5(key), 1, 0)
            end
        end
        dictionary:set("control:last_task_id", command.task_id)
    end
    ngx.header["Content-Type"] = "application/json"
    ngx.say(cjson.encode({data = {accepted = true, replayed = replayed, action = command.action, applied_keys = command.cache_keys and #command.cache_keys or 0}}))
end

return M
