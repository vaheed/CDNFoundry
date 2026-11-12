---
title: Log push
description: Send the logs of your own domains to an HTTPS endpoint or S3-compatible storage.
---

# Log push

Log push delivers the logs of one domain to an endpoint you control. It is separate from
the platform's central logs, which do not change.

**You need:** a domain-user account for the domain, and an administrator who has turned on
**Platform settings, Log push, Domain users can push logs to their own endpoint**. It is off by default.

## Add a destination

1. Open the domain and choose the **Log push** tab.
2. Choose **Add destination**. A domain has up to two.
3. Choose **HTTPS endpoint** or **S3-compatible storage**, then the logs to send:

| Log | Contains |
| --- | --- |
| HTTP requests | Each request served for the domain: time, request ID, host, method, path, query hash, status, bytes, cache status, timing, client address, country, ASN, user agent, referrer, protocol |
| Security events | The requests the platform blocked or flagged, with the action, reason, and rule |
| DNS queries | Questions answered for your zone: name, type, response code, client address, country, ASN |

4. For HTTPS, give an `https://` URL and choose how the platform authenticates. **Bearer token** sends your token in the
   `Authorization` header. **Signature** adds an `X-CDNFoundry-Signature` header (`sha256=` and the HMAC-SHA256 of the body)
   and shows you the signing secret once.
5. For S3, give the endpoint, bucket, region, an optional folder, and an access key. Each batch becomes one object named
   `folder/domain-id/log/year/month/day/time-id.ndjson.gz`.
6. Optionally truncate client addresses to `203.0.113.0` (IPv4 /24) or the first 48 bits (IPv6).
7. Choose **Send test**. The test is an operation; it sends one line and does not move the cursor.

Credentials are stored encrypted and are never shown again. The same actions are in the API under
`/api/domains/{domain}/log-push`.

## What you receive

A delivery is a gzip-compressed body of JSON lines, one row each, in time order. Each row has a `dataset`, an `event_id`, and
an `occurred_at` time (UTC) followed by that dataset's fields. Only rows of your own domain are ever read. Edge and cell names,
internal addresses, platform hostnames, other domains' IDs, and raw query strings are never included; a request carries only
its `query_hash`. Use `event_id` to drop a duplicate if a delivery is retried.

Delivery starts with the next new row after you add the destination and runs about once a minute. Rows from the last 30 seconds
are held back so a late insert is not skipped, so expect a delay of about a minute and a half.

## When a destination fails

A failing destination retries with a growing delay (one minute up to thirty) and keeps its place, so nothing is skipped. After
five failures in a row you get a **Log push failing** notification. The tab shows the last delivery, lag, failures, bytes sent,
and the last error on hover.

If a destination stays down longer than the platform keeps raw logs (7 days by default), it skips ahead to the oldest row that
still exists and records the gap.

## Limits and safety

- An endpoint goes through the same destination checks as an origin: no loopback, private, link-local, metadata, or platform
  address. The address is resolved again and pinned when the worker connects.
- One shared `log-pusher` worker serves every destination. Nothing runs per domain, and the worker is separate from the
  notifier, so a slow endpoint never delays alerts. Serving and central telemetry are never affected.
- A batch holds at most 5,000 rows, a destination sends at most five batches a minute, and the platform holds at most 2,000
  destinations. Administrators can change these in [Platform settings](../reference/platform-settings.md#log-push).
- When the administrator turns the switch off, every push stops within a minute and the destinations are kept.
