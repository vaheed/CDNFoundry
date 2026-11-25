---
title: WAF verification baseline
description: How the managed WAF's detection, false positives, evasion handling and cost are measured, the recorded baseline for the pinned rule set, and how later changes compare against it.
---

# WAF verification baseline

This page describes the harness that measures the managed WAF and records the baseline every later WAF change is compared against. The raw record is in [`evidence/waf-baseline-2026-11-26`](https://github.com/vaheed/CDNFoundry/tree/dev/docs/operations/evidence/waf-baseline-2026-11-26).

| Gate | Status |
| --- | --- |
| Implementation | Corpora, replay harness, comparison command, latency and CPU matrix, rule set version display on the Cells and Service pools lists |
| Documentation | This page, [Cell inventory](cell-inventory.md), [Testing](../development/testing.md) |
| Automated and runtime qualification | Harness tested against a stub cell and origin; full replay on a real cell at every protection and paranoia level; rule set mismatch tested with a changed image; alert rule unit test |
| Browser suite | `edge-health`, `screens-rich` and `every-screen` specs pass with the pools **Rule set** column |

## What is measured

Three versioned corpora are replayed against one real OpenResty cell from the edge-runtime image. Everything is synthetic: no request is recorded from a live system or built from customer data.

| Corpus | Contents | Identified by |
| --- | --- | --- |
| CRS regression | Every request stage of the OWASP Core Rule Set's own regression tests at the pinned commit: 5,199 stages, 5,115 replayed. One stage expects a connection error and 83 expect a response-body rule, which a stub origin cannot trigger, so those 84 are counted and skipped. Each stage keeps the paranoia level of the rule it expects, so a stage is counted as in scope only at a paranoia level that runs its rule. | The pinned commit |
| CDNFoundry evasions | 51 cases for this runtime: chunked bodies (one chunk and 3-byte chunks), HTTP/2 over TLS and in cleartext without a content length, double and `%u` encodings, UTF-7, a gzip body, comment-split SQL, traversal spellings, payloads in headers, cookies, JSON keys and multipart filenames, a wrong or missing content type, bodies around the inspection limits, and oversized headers and addresses. | `evasions-1` |
| Clean traffic | 10,300 requests in seven classes: browser page loads (3,000), form posts (1,500), JSON APIs (2,500), file uploads (600), mobile API clients (1,200), webhooks (700) and crawlers (800). | `clean-1`, seed `20261125`, a content digest |

The clean corpus has a second, separately reported part. About 6% of it (630 requests) are **hard negatives**: legitimate text that sits close to attack keywords, such as "drop me a line", a markdown `SELECT` in a comment or "use the onload event". Ordinary clean traffic is defined by a rule, not by outcome: it uses none of the words `select`, `union`, `drop`, `insert`, `delete`, `update`, `exec`, `eval`, `script`, `alert`, `onload`, `system` or `cmd`, and no markup. A contract test enforces that rule.

The clean corpus is generated, so it is a coverage input, not a measure of any real site. Do not read a false positive rate from it as a rate for production traffic.

## How it measures

The harness is a Python tool, `tests/e2e/waf_baseline.py`. It needs no hook in the runtime.

- One cell, from the image under test, runs with a 2 CPU and 2 GB limit. A stub origin records every request that reaches it.
- Thirteen hosts differ only in protection level (Off, Observe, Recommended, High) and paranoia level (1 to 4).
- Every request carries its own client address in `X-Forwarded-For`, alternating IPv4 and IPv6, each IPv6 address in its own /64. The throwaway cell trusts the header, so the access log row of a request joins to it uniquely and no per-client protection contains the harness.
- Nginx's per-client limits are raised in a copy of the cell's server configuration, as the capacity benchmark does.
- A request's outcome comes from the cell's access log and the origin together: **blocked** (the WAF denied it and the origin never saw it), **logged** (it reached the origin but was scored or monitored), **passed**, **refused** (the cell rejected it before the origin for another reason, by status), **error** (no answer) or **unlogged** (it reached the origin with no log row, a harness fault that is never counted as a pass).
- Every rate is given with its denominator and a 95% Wilson interval.

```sh
python3 tests/e2e/waf_baseline.py run --output record.json
python3 tests/e2e/waf_baseline.py compare docs/operations/evidence/waf-baseline-2026-11-26/waf-baseline.json record.json
```

`compare` exits with status 1 when in-scope detection falls, the clean false positive rate rises, or clean requests go unanswered, beyond a tolerance of 0.5 percentage points. Each later phase runs it before it closes. `make dev-waf-baseline` builds the image and writes both records to `storage/qualification/`.

## Recorded baseline

Measured on 2026-11-26 from commit `808c527d` with a clean tree, on a development host (32 vCPU Intel Xeon E5-2697 v4 at 2.3 GHz, kernel 6.8, Docker 29.1.3), against an image built with ModSecurity `1925753989cc` and CRS `e03a4f6dabc7`, the pinned commits. The harness and the cell shared the host, so the client latency in the record is not a capacity figure. A development-host record cannot alone close fleet qualification.

### Detection and false positives

"In scope" counts the stages whose expected rule runs at that paranoia level. "Detected" is blocked or logged.

| Level | Paranoia | In-scope attacks | Detected | Blocked | Ordinary clean requests | Clean blocked (95% interval) | Hard negatives blocked |
| --- | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| Off | none | 2,311 | 0.00% | 0.00% | 9,670 | 0.00% | 0.00% |
| Observe | 1 | 2,311 | 94.25% | 0.00% | 9,670 | 0.00% (0.00% to 0.04%) | 0.00% |
| Observe | 2 | 3,471 | 95.53% | 0.00% | 9,670 | 0.00% (0.00% to 0.04%) | 0.00% |
| Observe | 3 | 3,994 | 95.99% | 0.00% | 9,670 | 0.00% (0.00% to 0.04%) | 0.00% |
| Observe | 4 | 4,041 | 96.04% | 0.00% | 9,670 | 0.00% (0.00% to 0.04%) | 0.00% |
| Recommended | 1 | 2,311 | 94.25% | 93.21% | 9,670 | 9.18% (8.62% to 9.78%) | 17.62% |
| Recommended | 2 | 3,471 | 95.62% | 94.61% | 9,670 | 12.00% (11.36% to 12.66%) | 19.68% |
| Recommended | 3 | 3,994 | 96.07% | 95.54% | 9,670 | 25.75% (24.89% to 26.63%) | 36.19% |
| Recommended | 4 | 4,041 | 96.11% | 95.64% | 9,670 | 66.62% (65.67% to 67.55%) | 67.78% |
| High | 1 | 2,311 | 94.25% | 94.20% | 9,670 | 9.18% (8.62% to 9.78%) | 17.62% |
| High | 2 | 3,471 | 95.62% | 95.56% | 9,670 | 12.00% (11.36% to 12.66%) | 19.68% |
| High | 3 | 3,994 | 96.07% | 95.99% | 9,670 | 25.75% (24.89% to 26.63%) | 36.19% |
| High | 4 | 4,041 | 96.11% | 96.04% | 9,670 | 66.62% (65.67% to 67.55%) | 67.78% |

Detected but not blocked, at Recommended and paranoia 1, are the stages whose rules add fewer points than the threshold of 5. High lowers the threshold to 3 and blocks almost every detected stage.

By CRS category, at Recommended and paranoia 1 (in scope only):

| Category | Stages | Detected |
| --- | ---: | ---: |
| 911 Method enforcement | 4 | 25.00% |
| 913 Scanner detection | 6 | 100.00% |
| 920 Protocol enforcement | 149 | 68.46% |
| 921 Protocol attacks | 58 | 100.00% |
| 922 Multipart attacks | 23 | 0.00% |
| 930 Local file inclusion | 57 | 96.49% |
| 931 Remote file inclusion | 14 | 100.00% |
| 932 Remote code execution | 442 | 99.55% |
| 933 PHP injection | 274 | 93.07% |
| 934 Generic attacks | 124 | 100.00% |
| 941 Cross-site scripting | 188 | 100.00% |
| 942 SQL injection | 325 | 100.00% |
| 943 Session fixation | 42 | 100.00% |
| 944 Java attacks | 603 | 94.03% |
| 949 Blocking evaluation | 2 | 50.00% |

### What the numbers say

- **PUT, PATCH and DELETE are blocked on Recommended and High.** All 888 clean requests with those methods were blocked at every paranoia level, and none of the 8,782 GET, HEAD and POST requests were, at paranoia 1. This is the CRS method rule 911100 with the CRS default of GET, HEAD, POST and OPTIONS. It explains the whole 9.18% at paranoia 1: without it the clean false positive rate at paranoia 1 is 0 of 8,782. A REST API behind Recommended needs the method rule turned off or a scoped exclusion. This baseline records it and does not change it.
- **Paranoia 2 adds 2.8 points of false positives for 1.4 points of detection**, and paranoia 3 and 4 are not usable for general traffic: they block a quarter and two thirds of the clean corpus.
- **Observe detects exactly what Recommended detects**, without blocking.
- **Most of the shortfall is requests the cell refused, not requests it missed.** All 23 multipart (922) stages, 3 of 4 method (911) stages, 28 of 149 protocol (920) stages, 34 of 603 Java (944) stages and 6 of 274 PHP (933) stages were refused by the cell's own checks, with a status and no origin request, so they are counted as refused, not detected. The record lists the decisions per category.

### Evasion cases

At Recommended and paranoia 1, 39 of the 51 cases are blocked, 6 pass and 6 are refused by the cell before inspection. The same attack is blocked with a `Content-Length`, chunked, and over HTTP/2 with no length, in the query, form, JSON and multipart bodies.

- **The chunked-body case is blocked.** A chunked POST of a script tag is blocked on Recommended and High at every paranoia level, and logged on Observe. The same holds for 3-byte chunks, chunked JSON and multipart, and HTTP/2 over TLS and in cleartext.
- **The one attack that passes** is a script tag in a multipart filename, at Recommended. The `%u`, double-encoded, UTF-7, comment-split, full-width and null-byte spellings are blocked.
- **The other passes are controls that must pass:** a clean chunked POST, a clean HTTP/2 POST, a clean 2 KiB query, a clean 512 KiB body, and a clean body just above High's limit, which Recommended inspects.
- **Refused before inspection:** a gzip `Content-Encoding` body (a blocking WAF refuses a body it cannot read), a body above the inspection limit, a 40 KiB header, 300 headers, and 9 KiB and 70 KiB request addresses.
- **High refuses a 512 KiB body** that Recommended inspects, because High's inspection limit is 256 KiB.

## Latency and CPU

The matrix is `tests/e2e/waf_latency_matrix.py`, which drives the capacity benchmark. One cell with 1 CPU, 32 keep-alive connections, 20 hostnames, three 20-second runs after a 5-second warm-up, with `wrk` in its own container. A body of 0 is a cached GET of a 10 KB object; other bodies are POSTs that bypass the cache. A 1 MiB request is above High's inspection limit, so High refuses it by design and its status errors are recorded, not hidden.

Profile by body size (medians of three 20-second runs; latency is p50 / p95 / p99):

| Case | Requests per second | Latency | CPU per request | Peak memory | Errors |
| --- | ---: | --- | ---: | ---: | ---: |
| Off, cached GET | 2,286 | 13 / 24 / 33 ms | 436 µs | 76 MiB | 0 |
| Off, 4 KiB POST | 782 | 38 / 72 / 87 ms | 1,277 µs | 81 MiB | 0 |
| Off, 64 KiB POST | 481 | 63 / 109 / 129 ms | 2,071 µs | 84 MiB | 0 |
| Off, 1 MiB POST | 87 | 344 / 462 / 546 ms | 11,504 µs | 141 MiB | 0 |
| Observe, cached GET | 342 | 85 / 154 / 211 ms | 2,914 µs | 77 MiB | 0 |
| Observe, 4 KiB POST | 117 | 251 / 409 / 504 ms | 8,542 µs | 79 MiB | 0 |
| Observe, 64 KiB POST | 14 | 2,135 / 2,591 / 3,829 ms | 73,831 µs | 94 MiB | 0 |
| Observe, 1 MiB POST | 0 | none completed | none completed | 386 MiB | 0 |
| Recommended, cached GET | 298 | 97 / 181 / 230 ms | 3,361 µs | 78 MiB | 0 |
| Recommended, 4 KiB POST | 96 | 315 / 455 / 565 ms | 10,440 µs | 78 MiB | 0 |
| Recommended, 64 KiB POST | 13 | 2,141 / 2,944 / 4,307 ms | 81,019 µs | 95 MiB | 3 |
| Recommended, 1 MiB POST | 0 | none completed | none completed | 373 MiB | 0 |
| High, cached GET | 250 | 116 / 227 / 286 ms | 4,006 µs | 77 MiB | 0 |
| High, 4 KiB POST | 77 | 373 / 595 / 702 ms | 12,983 µs | 79 MiB | 0 |
| High, 64 KiB POST | 5 | 4,462 / 4,897 / 4,941 ms | 202,361 µs | 97 MiB | 198 |
| High, 1 MiB POST | 352 | 21 / 47 / 63 ms | 1,328 µs | 76 MiB | 23,678 |

Rules and detections (Recommended with a cached GET unless noted):

| Case | Requests per second | Latency | CPU per request | Peak memory | Errors |
| --- | ---: | --- | ---: | ---: | ---: |
| Recommended, no extra rules | 298 | 97 / 181 / 230 ms | 3,361 µs | 78 MiB | 0 |
| 10 CRS rules set to log only | 156 | 195 / 330 / 399 ms | 6,400 µs | 78 MiB | 0 |
| 100 custom firewall rules | 326 | 88 / 174 / 228 ms | 3,061 µs | 83 MiB | 0 |
| 1,000 custom firewall rules | 280 | 106 / 187 / 234 ms | 3,569 µs | 103 MiB | 0 |
| 20 rate-limit rules | 313 | 95 / 171 / 217 ms | 3,197 µs | 78 MiB | 0 |
| WAF Off, no extra rules | 2,286 | 13 / 24 / 33 ms | 436 µs | 76 MiB | 0 |
| WAF Off, 20 rate-limit rules | 1,825 | 16 / 31 / 53 ms | 548 µs | 76 MiB | 0 |
| WAF Off, 64 B POST (control for the detections) | 887 | 34 / 55 / 74 ms | 1,127 µs | 80 MiB | 0 |
| WAF Off, leaked-password check on a login POST | 896 | 34 / 50 / 76 ms | 1,116 µs | 80 MiB | 0 |
| WAF Off, AI prompt signals on a chat POST | 825 | 37 / 57 / 79 ms | 1,212 µs | 80 MiB | 0 |
| WAF Off, sensitive-data scan of a 10 KB uncached page | 306 | 97 / 172 / 224 ms | 3,266 µs | 78 MiB | 0 |

What the matrix shows:

- **The WAF costs 3,361 µs of CPU for a cached GET at Recommended, against 436 µs with it Off**, consistent with the 2,839 and 402 µs of the 2026-11-21 audit (same method, a different image and run). That is a workload-specific observation, not an SLO, and not a reason to turn protection off.
- **Inspection cost grows faster than body size.** A 4 KiB body costs about 10 ms of CPU at Recommended, a 64 KiB body 81 ms, and a 1 MiB body did not complete a single request inside the generator's 5-second timeout in any of the three runs (the cell's memory peaked near 380 MiB). With the WAF Off the same bodies cost 1.3, 2.1 and 11.5 ms. At 1 CPU, Recommended inspects about 13 requests a second of 64 KiB.
- **High refuses 1 MiB bodies at once** because its limit is 256 KiB: 23,678 refusals in a run at about 1.3 ms each, recorded as status errors. At 64 KiB High is slower than Recommended (202 ms against 81 ms of CPU) and 198 requests timed out.
- **Log-only rules double the cost.** Ten log-only CRS rules take a cached GET from 3,361 to 6,400 µs, because the cell scores the request twice to tell whether a log-only rule matched. The roadmap's inspection-cost phase evaluates removing that duplicate pass.
- **Custom firewall rules are cheap**: 100 never-matching rules cost nothing measurable (3,061 µs, inside the noise of the control), and 1,000 cost about 200 µs. Twenty rate-limit rules that count every request cost about 110 µs with the WAF Off. The leaked-password check and the AI prompt signals cost under 100 µs over a plain POST. The sensitive-data scan of an uncached 10 KB page has no uncached control in this matrix, so its cost is not isolated.
- Peak memory stayed under 105 MiB except while a 1 MiB body was being inspected.

## Limits of this baseline

- A development host and a generated clean corpus. Neither is a statement about production traffic, and none of the rates above is an SLO.
- The detection rate counts the CRS project's own regression stages. It measures whether the pinned rule set detects its own tests through this runtime, not how it performs against attackers who adapt.
- Response-body rules are not exercised: the stub origin never sends a leaking response.
- The harness and the cell shared one host. Client latency in the record is indicative only; CPU per request comes from the cell's cgroup.
- An image that predates matched-rule reporting records no matched rule ids, so this baseline cannot compare them. See [Managed WAF](../guides/managed-waf.md) for what a block now reports.

## Rule set versions per cell

Each cell reports the WAF engine and rule set commits its image was built from. The Cells and Service pools lists compare them with the versions pinned by the release, and the `CellRulesetDiffers` alert fires when a running cell differs or does not report. See [Cell inventory](cell-inventory.md#rule-set-version). The control plane records a cell's commits on its first heartbeat after it starts. The alert needs the mismatch to last 2 minutes, so that a cell replaced during a rollout does not page anyone; the cells list and the metric show it sooner.

The mismatch is tested by starting a cell from an image whose recorded commit is changed (`tests/e2e/ruleset_version_mismatch.py`) and by a Prometheus rule test.
