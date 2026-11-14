-- Request-body acquisition for the managed WAF. Whether a request has a body is decided by what the Nginx request-body reader
-- delivers, never by the Content-Length header: a chunked HTTP/1.1 body and an HTTP/2 body carry no Content-Length, and a
-- header-only test reads them as empty. Every call returns an explicit outcome, and only "none", "empty" and "complete" mean the
-- body is known in full; any other outcome must never be treated as a clean inspection.
--
--   none                  the request has no body
--   empty                 a body was declared and is zero bytes long
--   complete              the whole body was read and is returned
--   too_large             the body is larger than the inspection limit
--   unreadable            the body could not be read completely, or it is shorter than it declared
--   unsupported_encoding  the body is content-encoded, so the WAF would score the encoded bytes and not the entity
local M = {}

-- No inspection limit may exceed this, so one request never holds more than this much body in Lua memory.
M.CEILING = 1048576

-- The body as Nginx holds it, up to the ceiling. Returns an outcome and, for "complete", the body.
local function read_whole()
    local declared = tonumber(ngx.var.http_content_length)
    if declared and declared > M.CEILING then return "too_large" end
    local ok = pcall(ngx.req.read_body)
    if not ok then return "unreadable" end
    local data = ngx.req.get_body_data()
    local file_path = ngx.req.get_body_file()
    local body
    if data then
        body = data
    elseif file_path then
        local file = io.open(file_path, "rb")
        if not file then return "unreadable" end
        local size = file:seek("end")
        if not size then file:close(); return "unreadable" end
        if size > M.CEILING then file:close(); return "too_large" end
        file:seek("set", 0)
        body = size == 0 and "" or file:read(size)
        file:close()
        if not body then return "unreadable" end
    end
    if body == nil or #body == 0 then
        if declared or ngx.var.http_transfer_encoding then return "empty", "" end
        return "none", ""
    end
    if #body > M.CEILING then return "too_large" end
    -- A body shorter than its declared length was cut off on its way in; it is not the request the client sent.
    if declared and #body ~= declared then return "unreadable" end
    local encoding = (ngx.var.http_content_encoding or ""):lower():gsub("%s+", "")
    if encoding ~= "" and encoding ~= "identity" then return "unsupported_encoding" end
    return "complete", body
end

-- The outcome for one inspection limit. The body is read once per request and shared by every caller.
function M.get(limit)
    local ctx = ngx.ctx
    if not ctx.waf_body_read then ctx.waf_body_read = {read_whole()} end
    local outcome, body = ctx.waf_body_read[1], ctx.waf_body_read[2]
    if outcome == "complete" and #body > limit then return "too_large" end
    return outcome, body
end

-- Whether the outcome means the whole body, or the absence of one, is known.
function M.is_known(outcome)
    return outcome == "none" or outcome == "empty" or outcome == "complete"
end

return M
