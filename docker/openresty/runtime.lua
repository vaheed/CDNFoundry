local cjson = require "cjson.safe"
local resolver = require "resty.dns.resolver"
local balancer = require "ngx.balancer"
local bit = require "bit"
local ffi = require "ffi"
ffi.cdef[[int kill(int pid, int sig);]]
local M = {}
local state = { hosts = {}, sequence = 0 }
local path = os.getenv("EDGE_RUNTIME_FILE") or "/var/lib/cdnfoundry/runtime/active.json"
local restart_generation = 0

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
    if not state.hosts[name] then
        error("unknown TLS SNI")
    end
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
    ngx.shared.runtime_limits:incr("capacity:rejected_requests", 1, 0)
    return ngx.exit(status)
end

function M.access()
    local dictionary = ngx.shared.runtime_limits
    dictionary:incr("capacity:requests:" .. ngx.time(), 1, 0, 2)
    if dictionary:get("control:drained") == true then return reject(503) end
    local host = (ngx.var.host or ""):lower():gsub("%.$", "")
    local config = state.hosts[host]
    if not config then return reject(421) end
    if config.settings and config.settings.enabled == false then return reject(503) end
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
    local origin = config.origin
    local address, err = resolve(origin.host, origin.private_allowlist, origin.blocked_networks, origin.blocked_addresses)
    if not address then ngx.log(ngx.WARN, "origin rejected: ", err); ngx.header["X-CDNFoundry-Error"] = err; return reject(502) end
    if address:find(":", 1, true) then address = "[" .. address .. "]" end
    ngx.var.origin_scheme = origin.scheme
    ngx.var.origin_address = address
    ngx.var.origin_port = tostring(origin.port)
    ngx.var.origin_host_header = origin.host_header
    ngx.var.origin_sni = origin.sni or origin.host_header
    ngx.var.origin_connection = ""
    ngx.var.origin_upgrade = ""
    ngx.var.origin_address = address:gsub("^%[", ""):gsub("%]$", "")
    ngx.var.origin_connect_timeout = tostring(math.max(100, math.min(10000, tonumber(origin.connect_timeout_ms) or 1000)))
    ngx.var.origin_response_timeout = tostring(math.max(500, math.min(60000, tonumber(origin.response_timeout_ms) or 5000)))
    ngx.var.origin_retry_count = tostring(math.max(0, math.min(2, tonumber(origin.retry_count) or tonumber(config.settings and config.settings.retry_count) or 0)))
    if origin.websocket == true and (ngx.var.http_upgrade or ""):lower() == "websocket" then
        ngx.var.origin_connection = "upgrade"
        ngx.var.origin_upgrade = "websocket"
    end
    ngx.req.clear_header("Forwarded"); ngx.req.clear_header("X-Forwarded-For"); ngx.req.clear_header("X-Forwarded-Host"); ngx.req.clear_header("X-Forwarded-Proto")
    ngx.req.clear_header("Proxy-Connection"); ngx.req.clear_header("Keep-Alive"); ngx.req.clear_header("TE"); ngx.req.clear_header("Trailer"); ngx.req.clear_header("Upgrade")
    dictionary:incr("capacity:origin_connections", 1, 0)
    if origin.scheme == "https" and origin.verify_tls == true then return ngx.exec("@proxy_verified") end
    if origin.scheme == "https" then return ngx.exec("@proxy_unverified_https") end
    return ngx.exec("@proxy_http")
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
    ngx.shared.runtime_limits:incr("capacity:origin_connections", -1, 0)
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
        or (command.action ~= "drain" and command.action ~= "undrain" and command.action ~= "restart" and command.action ~= "cache_purge") then
        return ngx.exit(400)
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
