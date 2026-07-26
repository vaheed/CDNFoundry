# Cache purge troubleshooting

Cache purges are asynchronous. A successful API or browser response means desired purge state was stored; it does not mean every edge has already applied it.

## Inspect delivery

Use `GET /api/domains/{domain}/cache/purges/{purge}` and inspect every edge task. `running` means at least one edge is outstanding. A failed cell control call retries the same durable task with bounded exponential delays. Five failed attempts make that edge task and the purge terminally failed without creating another user-visible purge.

Confirm that the edge identity is valid, its heartbeat is current, every configured cell status endpoint is reachable from the agent, and the shared status token matches. Replaying a task ID is safe.

## Full purge

A full purge increments the domain epoch. New requests use the new epoch immediately after the cell applies the command; old cache files become unreachable and age out under the cell quota. Never scan or manually delete cache directories during ordinary operation.

## URL purge

URL purges accept at most 100 absolute HTTP/HTTPS URLs and 128 KiB. Scheme, canonical host, raw path, and—when configured—raw query bytes and ordering must match the request. A URL for an unrelated domain, non-default port, credentials, or fragment is rejected. The runtime increments the generation of the exact canonical key.

## Backlog protection

A domain may have at most 1,000 outstanding purges. When this bound is reached, wait for edge delivery or repair failed edges. Do not submit replacement purges merely to work around a failed task; the original task owns its retries and visible result.
