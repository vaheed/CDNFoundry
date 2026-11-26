-- Named IP lists. The control plane compiles each list into sorted, non-overlapping address ranges, so a cell answers
-- "is this address on the list" with a binary search instead of scanning networks. A cell never builds a list from anything
-- but the signed snapshot it was given.
--
-- A compiled list is {v4 = {first1, last1, first2, last2, ...}, v6 = {a1, b1, c1, d1, a2, b2, c2, d2, ...}}: IPv4 ranges as whole
-- numbers, IPv6 ranges as four 32-bit words per address (first address, then last), which order the same way the addresses
-- do and compare without building strings. A malformed list never matches.
local M = {}

M.MAXIMUM_RANGES = 10000

local byte = string.byte

local WORDS = 4
local STRIDE = WORDS * 2

local function word_less(a1, a2, a3, a4, b1, b2, b3, b4)
    if a1 ~= b1 then return a1 < b1 end
    if a2 ~= b2 then return a2 < b2 end
    if a3 ~= b3 then return a3 < b3 end
    return a4 < b4
end

local function is_word(value) return type(value) == "number" and value >= 0 and value <= 4294967295 and value % 1 == 0 end

-- The ranges a lookup may read: whole-number pairs for IPv4 and four-word pairs for IPv6, each side sorted, ordered by first
-- address, and not overlapping, in a bounded number. The agent checks this before a snapshot is activated.
function M.valid(list)
    if type(list) ~= "table" then return false end
    local v4, v6 = list.v4 or {}, list.v6 or {}
    if type(v4) ~= "table" or type(v6) ~= "table" or #v4 % 2 ~= 0 or #v6 % STRIDE ~= 0 or #v4 / 2 + #v6 / STRIDE > M.MAXIMUM_RANGES then return false end
    local previous = -1
    for index = 1, #v4, 2 do
        local first, last = v4[index], v4[index + 1]
        if not is_word(first) or not is_word(last) or first > last or first <= previous then return false end
        previous = last
    end
    for index = 1, #v6, STRIDE do
        for offset = 0, STRIDE - 1 do
            if not is_word(v6[index + offset]) then return false end
        end
        if word_less(v6[index + 4], v6[index + 5], v6[index + 6], v6[index + 7], v6[index], v6[index + 1], v6[index + 2], v6[index + 3]) then return false end
        if index > 1 and not word_less(v6[index - 4], v6[index - 3], v6[index - 2], v6[index - 1], v6[index], v6[index + 1], v6[index + 2], v6[index + 3]) then return false end
    end
    return true
end

-- Whether a packed client address (4 bytes for IPv4, 16 for IPv6) is inside one of the list's ranges.
function M.contains(list, packed)
    if type(list) ~= "table" or type(packed) ~= "string" then return false end
    if #packed == 4 then
        local ranges = list.v4
        if type(ranges) ~= "table" then return false end
        local a, b, c, d = byte(packed, 1, 4)
        local value = a * 16777216 + b * 65536 + c * 256 + d
        local low, high, found = 1, #ranges / 2, nil
        while low <= high do
            local middle = math.floor((low + high) / 2)
            if ranges[middle * 2 - 1] <= value then found = middle; low = middle + 1 else high = middle - 1 end
        end
        return found ~= nil and value <= ranges[found * 2]
    end
    if #packed == 16 then
        local ranges = list.v6
        if type(ranges) ~= "table" then return false end
        local b1, b2, b3, b4, b5, b6, b7, b8, b9, b10, b11, b12, b13, b14, b15, b16 = byte(packed, 1, 16)
        local w1, w2, w3, w4 = b1 * 16777216 + b2 * 65536 + b3 * 256 + b4, b5 * 16777216 + b6 * 65536 + b7 * 256 + b8,
            b9 * 16777216 + b10 * 65536 + b11 * 256 + b12, b13 * 16777216 + b14 * 65536 + b15 * 256 + b16
        local low, high, found = 1, #ranges / STRIDE, nil
        while low <= high do
            local middle = math.floor((low + high) / 2)
            local base = (middle - 1) * STRIDE
            -- The range's first address is at or before the client's.
            if not word_less(w1, w2, w3, w4, ranges[base + 1], ranges[base + 2], ranges[base + 3], ranges[base + 4]) then found = base; low = middle + 1 else high = middle - 1 end
        end
        return found ~= nil and not word_less(ranges[found + 5], ranges[found + 6], ranges[found + 7], ranges[found + 8], w1, w2, w3, w4)
    end
    return false
end

return M
