-- Per-domain entry budgets inside a shared dictionary. A cell's counters for every domain live in a few fixed-size
-- dictionaries, and a dictionary that is full silently evicts its least recently used entries to make room for a new key. One
-- domain that sees many distinct client keys would therefore evict the counters and mitigations of every other domain on the
-- cell. A budget gives each domain its own share of the dictionary: a key beyond the share is refused, so the dictionary never
-- fills and nothing of another domain is evicted. The refused key is simply not counted, which is the policy for any counter
-- that has no room.
local M = {}

-- What one small entry (node, key and value) costs in the slab allocator, rounded up to its size class.
M.ENTRY_BYTES = 256
-- The part of a dictionary that budgets add up to. The rest absorbs slab fragmentation and the bookkeeping keys below.
M.BUDGET_FRACTION = 0.5
-- A domain always gets at least this many entries, however many domains share the cell.
M.MINIMUM_SHARE = 100

-- How many entries one domain may hold in the dictionary at any time: an equal part of the budgeted capacity.
function M.share(dictionary, domains)
    local entries = math.floor(dictionary:capacity() * M.BUDGET_FRACTION / M.ENTRY_BYTES)
    return math.max(M.MINIMUM_SHARE, math.floor(entries / math.max(1, domains)))
end

-- Admits one new entry of a domain against a per-window limit. An entry that lives for at most two windows is counted in the
-- window it was created in, so the count needs no release: it expires with the window. Returns whether the entry fits and how
-- many were admitted so far, so a caller can report the first refusal of a window once.
function M.admit(dictionary, domain, kind, width, limit)
    local window = math.floor(ngx.time() / width)
    local used = dictionary:incr("n:" .. tostring(domain) .. ":" .. kind .. ":" .. window, 1, 0, width * 2)
    if not used then return false, 0 end
    return used <= limit, used
end

return M
