-- The key under which a cell counts one client. A host controls a whole IPv6 prefix, normally a /64, and can send each request
-- from a new address inside it, so counting per full IPv6 address gives it a fresh allowance every time. The key therefore
-- keeps only the leading bits of an IPv6 address (64 by default, the same grouping the gateway uses for connection slots).
-- Addresses of networks that put many unrelated users behind one prefix are listed as exceptions and keep their full address.
-- The key is also canonical: the same address written two ways, or an IPv4 address written as IPv4-mapped IPv6, is one client.
--
-- The key is the binary form of the address: 4 bytes for IPv4 and 16 for IPv6, so the two never collide. It is used for
-- counters only. Rule conditions, geography, logs, and the address sent to the origin keep the real client address.
local M = {}

M.DEFAULT_PREFIX = 64
M.MINIMUM_PREFIX = 32
M.MAXIMUM_EXCEPTIONS = 64

-- Whether the first `bits` bits of two byte strings agree.
local function same_prefix(a, b, bits)
    local whole, remainder = math.floor(bits / 8), bits % 8
    if a:sub(1, whole) ~= b:sub(1, whole) then return false end
    if remainder == 0 then return true end
    -- Compare the leading bits of the next byte without a bit library, so the module also runs where none is loaded.
    local unit = 2 ^ (8 - remainder)
    return math.floor(a:byte(whole + 1) / unit) == math.floor(b:byte(whole + 1) / unit)
end

-- The configured prefix length, or the default when the value is missing or outside 32 to 128.
function M.prefix(value)
    local bits = tonumber(value)
    if not bits or bits ~= math.floor(bits) or bits < M.MINIMUM_PREFIX or bits > 128 then return M.DEFAULT_PREFIX, value ~= nil and value ~= "" end
    return bits, false
end

-- Compiles a comma-separated list of IPv6 networks, given as CIDR or as one address, into {bytes, bits} pairs. Returns the
-- networks and the entries that were refused, so the caller can report them.
function M.exceptions(list, pack)
    local networks, refused = {}, {}
    for entry in tostring(list or ""):gmatch("[^,%s]+") do
        local address, bits = entry:match("^([^/]+)/(%d+)$")
        local packed = pack(address or entry)
        bits = bits and tonumber(bits) or 128
        if packed and #packed == 16 and bits >= 0 and bits <= 128 and #networks < M.MAXIMUM_EXCEPTIONS then
            networks[#networks + 1] = {packed, bits}
        else
            refused[#refused + 1] = entry
        end
    end
    return networks, refused
end

-- Builds the function that turns a client address into its counting key. `pack` parses an address into 4 or 16 bytes, or nil.
-- Text that does not parse is returned unchanged, so it is still counted, as itself.
function M.new(options)
    local pack, prefix, exceptions = options.pack, options.prefix or M.DEFAULT_PREFIX, options.exceptions or {}
    local mask_whole, mask_remainder = math.floor(prefix / 8), prefix % 8
    return function(address)
        local packed = pack(address)
        if not packed then return address end
        if #packed == 4 then return packed end
        -- ::ffff:a.b.c.d is the IPv4 address a.b.c.d.
        if packed:sub(1, 10) == string.rep("\0", 10) and packed:sub(11, 12) == "\255\255" then return packed:sub(13, 16) end
        for _, network in ipairs(exceptions) do
            if same_prefix(packed, network[1], network[2]) then return packed end
        end
        local kept = packed:sub(1, mask_whole)
        if mask_remainder > 0 then
            local byte = packed:byte(mask_whole + 1)
            local unit = 2 ^ (8 - mask_remainder)
            kept = kept .. string.char(math.floor(byte / unit) * unit)
        end
        return kept .. string.rep("\0", 16 - #kept)
    end
end

-- The text of a counting key for a report to the control plane: dotted decimal for IPv4, eight hexadecimal groups for IPv6 (the key
-- already has the bits beyond its prefix cleared). Anything that is not one of the two binary forms has no text, so it is not reported.
function M.text(key)
    if type(key) ~= "string" then return nil end
    if #key == 4 then return string.format("%d.%d.%d.%d", key:byte(1, 4)) end
    if #key ~= 16 then return nil end
    local groups = {}
    for index = 1, 16, 2 do
        groups[#groups + 1] = string.format("%x", key:byte(index) * 256 + key:byte(index + 1))
    end
    return table.concat(groups, ":")
end

return M
