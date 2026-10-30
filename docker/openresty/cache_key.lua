-- Canonical cache-key target. The PHP purge implementation
-- (core/app/Support/CacheKey.php) must produce identical output; both are
-- tested against tests/fixtures/cache_key_vectors.json.
--
-- The input is the raw request target exactly as the client sent it
-- (path plus optional query, no fragment). The path is kept encoded: only
-- percent-escape case and escapes of unreserved characters are normalized, so
-- %2F and %3F never merge with "/" and "?". A target that the origin could
-- read differently from the cache (dot segments, empty segments, malformed
-- escapes, unexpected characters) has no key and is never cached.
local M = {}

M.MAXIMUM_QUERY_ARGUMENTS = 128

local function unreserved(byte)
    return (byte >= 48 and byte <= 57) or (byte >= 65 and byte <= 90) or (byte >= 97 and byte <= 122)
        or byte == 45 or byte == 46 or byte == 95 or byte == 126
end

local function hex_value(byte)
    if byte >= 48 and byte <= 57 then return byte - 48 end
    if byte >= 65 and byte <= 70 then return byte - 55 end
    if byte >= 97 and byte <= 102 then return byte - 87 end
    return nil
end

local PATH_LITERAL = {}
for _, character in ipairs({"!", "$", "&", "'", "(", ")", "*", "+", ",", ";", "=", ":", "@", "/"}) do
    PATH_LITERAL[character:byte()] = true
end

-- Returns the normalized (still encoded) path, or nil and a reason.
local function normalize_path(path)
    if path:sub(1, 1) ~= "/" then return nil, "not_origin_form" end
    local out, index, length = {}, 1, #path
    while index <= length do
        local byte = path:byte(index)
        if byte == 37 then
            local high, low = hex_value(path:byte(index + 1) or 0), hex_value(path:byte(index + 2) or 0)
            if not high or not low then return nil, "invalid_percent_encoding" end
            local decoded = high * 16 + low
            if unreserved(decoded) then
                out[#out + 1] = string.char(decoded)
            else
                out[#out + 1] = string.format("%%%02X", decoded)
            end
            index = index + 3
        elseif unreserved(byte) or PATH_LITERAL[byte] then
            out[#out + 1] = string.char(byte)
            index = index + 1
        else
            return nil, "invalid_path_character"
        end
    end
    local normalized = table.concat(out)
    local segments, position = {}, 2
    while true do
        local slash = normalized:find("/", position, true)
        segments[#segments + 1] = normalized:sub(position, (slash or #normalized + 1) - 1)
        if not slash then break end
        position = slash + 1
    end
    for number, segment in ipairs(segments) do
        if segment == "." or segment == ".." then return nil, "dot_segment" end
        if segment == "" and number < #segments then return nil, "empty_segment" end
    end
    if normalized:sub(1, 2) == "//" then return nil, "empty_segment" end

    return normalized
end

local function decode_component(raw)
    local out, index, length = {}, 1, #raw
    while index <= length do
        local byte = raw:byte(index)
        if byte == 37 then
            local high, low = hex_value(raw:byte(index + 1) or 0), hex_value(raw:byte(index + 2) or 0)
            if not high or not low then return nil end
            out[#out + 1] = string.char(high * 16 + low)
            index = index + 3
        else
            out[#out + 1] = byte == 43 and " " or string.char(byte)
            index = index + 1
        end
    end

    return table.concat(out)
end

local function encode_component(value)
    return (value:gsub("[^%w%-%._~]", function(character)
        return string.format("%%%02X", character:byte())
    end))
end

-- policy: include_all | ignore_all | include_selected | ignore_selected.
-- Returns "<path>[?<query>]", or nil and a reason.
function M.target(raw_target, policy, parameters)
    local question = raw_target:find("?", 1, true)
    local path = question and raw_target:sub(1, question - 1) or raw_target
    local raw_query = question and raw_target:sub(question + 1) or ""
    local normalized, reason = normalize_path(path)
    if not normalized then return nil, reason end

    local selected = {}
    for _, name in ipairs(parameters or {}) do selected[name] = true end
    local pairs_, count = {}, 0
    for raw_pair in raw_query:gmatch("[^&]+") do
        count = count + 1
        if count > M.MAXIMUM_QUERY_ARGUMENTS then return nil, "too_many_query_arguments" end
        local equals = raw_pair:find("=", 1, true)
        local name = decode_component(equals and raw_pair:sub(1, equals - 1) or raw_pair)
        local value = decode_component(equals and raw_pair:sub(equals + 1) or "")
        if not name or not value then return nil, "invalid_percent_encoding" end
        local include = policy == "include_all" or policy == nil
            or (policy == "include_selected" and selected[name])
            or (policy == "ignore_selected" and not selected[name])
        if include then pairs_[#pairs_ + 1] = {name, value} end
    end
    table.sort(pairs_, function(left, right)
        if left[1] ~= right[1] then return left[1] < right[1] end
        return left[2] < right[2]
    end)
    local encoded = {}
    for _, pair in ipairs(pairs_) do
        encoded[#encoded + 1] = encode_component(pair[1]) .. "=" .. encode_component(pair[2])
    end
    if #encoded == 0 then return normalized end

    return normalized .. "?" .. table.concat(encoded, "&")
end

return M
