# Request, connection, cache, and origin limits

Limits are per hostname and compiled with its numeric domain ID. Request zones
use both domain/client-IP and total-domain keys; connection zones separately
bound one client and the whole domain. Shared dictionaries have fixed sizes, and
only bounded identifiers enter keys.

Profiles bound request rate and burst, client/domain connections, TLS
handshakes, body/header sizes, header/body/keep-alive/request timeouts, requests
per connection, origin concurrency, connect/read/send timeouts, retries,
circuit thresholds/recovery, cache-key length, and cache admissions. Values and
current ceilings are returned by `GET /api/domains/{domain}/security` and shown
in the domain Security action.

Rejection occurs before origin work when possible. Origin concurrency is
reserved before proxying, retries are capped, and repeated failures open a
bounded circuit. Capacity exhaustion or an open circuit returns a controlled
response, while cached/stale policy may continue serving. Cache admission
rejects excessive or oversized keys and the cell cache/temp directories have
fixed quotas. No per-domain shared-memory zone, process, or cache directory is
created.

HTTP/3 and WebSocket are disabled. Nginx applies bounded HTTP/1 and HTTP/2
headers, bodies, streams, keep-alive requests, timeouts, listen backlog, worker
connections, and temporary storage. Each cell also has explicit CPU, memory,
PID, file-descriptor, and filesystem limits.
