-- Local MMDB handles are replaced only after a published revision opens successfully.
local M = {}
local databases = {
    geography = {environment = "GEOIP_DATABASE"},
    asn = {environment = "GEOIP_ASN_DATABASE"},
}

function M.refresh(premature)
    if premature then return end
    local ok_module, maxminddb = pcall(require, "maxminddb")
    if not ok_module then
        ngx.log(ngx.WARN, "GeoIP module unavailable: ", maxminddb)
        return
    end
    for name, entry in pairs(databases) do
        local path = os.getenv(entry.environment) or ""
        if path ~= "" then
            local stamp_file = io.open(path .. ".revision", "r")
            local stamp = ""
            if stamp_file then stamp = stamp_file:read(65) or ""; stamp_file:close() end
            if not entry.database or stamp ~= entry.stamp then
                local ok_open, database = pcall(maxminddb.open, path)
                if ok_open and database then
                    -- Each lookup finishes without yielding, so the previous handle can be collected safely.
                    entry.database, entry.stamp = database, stamp
                else
                    ngx.log(ngx.WARN, "GeoIP ", name, " database unavailable; retaining previous handle: ", database)
                end
            end
        end
    end
end

local function lookup(name, ip)
    local database = databases[name].database
    if not database then return nil end
    local ok, result = pcall(database.lookup, database, ip)
    if ok then return result end
end

function M.geography(ip)
    local result = lookup("geography", ip)
    if not result then return "unknown", "unknown" end
    local ok_country, country = pcall(result.get, result, "country", "iso_code")
    local ok_continent, continent = pcall(result.get, result, "continent", "code")
    return ok_country and country or "unknown", ok_continent and continent or "unknown"
end

function M.autonomous_system(ip)
    local result = lookup("asn", ip)
    if not result then return 0 end
    local ok_number, number = pcall(result.get, result, "autonomous_system_number")
    number = ok_number and tonumber(number) or 0
    if not number or number ~= number or number < 0 or number > 4294967295 then return 0 end
    return math.floor(number)
end

return M
