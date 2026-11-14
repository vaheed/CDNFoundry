---
title: Grafana by team
description: Where each team starts in Grafana, what to check every day, and which dashboard to open when something goes wrong.
---

# Grafana by team

Grafana shows how your CDN is doing. Each team gets its own folders and its own
first screen, so nobody has to search through 35 dashboards. This page tells
each team where to start and what to do with what it sees.

**You need:** a Grafana login for your team. An administrator creates it and adds
you to the team. See [Grafana dashboards](grafana.md#start-and-sign-in) for how
to sign in. Grafana is read-only. It never changes your CDN.

## Find your team

| You are | Your team | First screen | Folders you see |
| --- | --- | --- | --- |
| Network operations | `noc` | **Now** | Overview, NOC, On-call and SLO, Samples |
| Security operations | `soc` | **Security overview** | Overview, SOC, On-call and SLO, Samples |
| Platform administrator | `platform-admin` | **Control plane** | Every folder. The only team that can edit |
| Customer support | `support` | **Domain overview** | Overview, Domains |
| Business and capacity | `business` | **Usage and billing** | Overview, Business and capacity |

Every dashboard lists its panels and what they show in the
[dashboards reference](../reference/grafana-dashboards.md).

## Read any dashboard

<Screenshot name="grafana-now" alt="The Now dashboard in Grafana, with a status sentence, a table of firing alerts, and tiles for health and traffic." />

*The screenshot uses generated demo data, so it shows a degraded platform.*

1. **Look at the top row.** Seven filters narrow every panel: environment,
   region, PoP, edge, pool, domain, and hostname. Each starts at **All**, which
   means all PoPs added together.
2. **Pick a time range.** The default is the last hour. Most dashboards refresh
   by themselves, every 10 or 30 seconds.
3. **Hover the small circle next to a panel title.** It explains what the panel
   shows.
4. **Use the switches for annotations.** They draw a mark on the graphs for
   fleet rollouts, artifact publishes, cache purges, settings changes, emergency
   mode, and critical alerts. A mark just before a change in a graph is often
   the cause.
5. **Follow the links.** A request ID in a log line opens **Request trace**. A
   domain opens **Domain overview**. A PoP opens **PoP detail**. **Runbook**
   opens the steps for that dashboard, and **Siblings** lists related
   dashboards.

## Network operations (NOC)

**Start with:** **Now**. Its first line is one sentence, such as "Degraded: 10
components unhealthy, 4 edges stale", or a statement that everything reports in.
Below it is the table of firing alerts. Each row links to the dashboard that
explains it, opened at the time the alert started.

**Every day:**

1. Open **Now**. Read the sentence and the Health tiles: Unhealthy, Stale,
   Failed ops, DNS drift, Alerts, and Paused.
2. Open **PoP fleet**. It has one row per PoP, with health, heartbeat, applied
   revision, traffic, errors, and latency.
3. Open **Synthetic probes** to see each probe's result and certificate expiry.

**When something is wrong:**

| You see | Open | Then |
| --- | --- | --- |
| A PoP looks unhealthy or stale | **PoP detail** | Check its cells, gateway, host resources, and logs |
| Users report errors | **Origin health**, then **Edge latency** | Tell an origin problem from an edge problem |
| Cache hit ratio dropped | **Cache** | Check purge volume and purge propagation |
| A change has not reached a PoP | **Config & purge propagation** | The slowest PoP is named |
| DNS answers look wrong | **DNS authoritative** | Check QPS, response codes, and serial propagation |

**On a big screen:** open **NOC wall**. It shows global traffic, a map of PoPs,
probes, and alerts on one screen with no scrolling.

<Screenshot name="grafana-noc-wall" alt="The NOC wall dashboard, built for a large display." />

## Security operations (SOC)

**Start with:** **Security overview**. It shows blocks, WAF matches, domains in
emergency mode, security events by reason, and the most attacked domains, each
with a trend.

**Every day:**

1. Open **Security overview** and compare the trends with yesterday.
2. Open **Telemetry integrity**. It shows rejected or forged events and
   insert failures. If it is not clean, the other security panels may be
   incomplete.
3. Open **TLS posture** to check certificates by expiry and ACME failures.

**When something is wrong:**

| You see | Open | Then |
| --- | --- | --- |
| A sudden rise in requests to a domain | **L7 attack and flood** | See the top networks, ASNs, countries, user agents, and paths |
| Many blocked requests | **WAF** | See which rules fire and which exclusions are in use |
| A DNS flood | **DNS abuse** | See random-subdomain floods and response-limit hits |
| Handshake errors | **TLS posture** | See failures by reason |
| Suspicious sign-ins | **Access and audit** | See failed sign-ins and token use |

For the response steps, follow the [runbooks](runbooks.md).

## Platform administrators

**Start with:** **Control plane**. It shows component health, API and panel
latency, Horizon queues, scheduler runs, failed jobs, and ACME orders.

**Every day:** open **Daily checks**. It counts the checks that fail, are not
reported, and pass, and lists each one with what it checks and what to do when it
fails, failures first. **Not reported** means the metric behind it is missing,
usually because that part is not installed (a second control node), not that it
failed. Then check **Backups and recovery** for the age of the last backup
and the result of the last integrity check.

| You see | Open |
| --- | --- |
| A rollout is paused or slow | **Fleet and rollouts** |
| A database or log store is filling up | **Data stores** |
| A host is short of CPU, memory, or disk | **Hosts and containers** |
| Events are late or missing in analytics | **Telemetry pipeline** |
| An alert fires | **Incident view**, then the runbook |

You are the only team that can edit dashboards, and you do it in the
repository, not in the browser. See [Grafana dashboards](grafana.md).

## Support

**Start with:** **Domain overview**. Pick the customer's domain in the filter at
the top. It shows that domain's traffic, cache, origin, security, TLS, DNS,
purge history, and configuration changes. Use **Domain comparison** to put
several domains side by side.

To follow one failing request, copy its request ID and open **Request trace**.
It follows the request from the edge log to the origin result and the
operational logs.

## Business and capacity

**Start with:** **Usage and billing**. It shows monthly bandwidth, 95th-percentile
bandwidth, requests, and DNS queries per domain, pool, and region. It is built
from rollups only.

- **Growth** shows domains, traffic, and bandwidth by month, and which domains
  grow fastest.
- **Capacity** shows headroom per PoP and pool for bandwidth, connections, cache,
  and CPU, and a 30-day forecast that names the first PoP to run out.

## On-call

Everyone on call can open the **On-call and SLO** folder:

- **SLOs** shows each target, the current value, the remaining error budget, and
  the burn rate.
- **Incident view** shows one alert or time range with its metrics, related
  logs, annotations, and affected domains and PoPs.
- **Logs explorer** has saved log queries for each service, with live tail.
- **Request trace** follows one request ID end to end.

When an alert fires, open its dashboard from **Now**, then follow its runbook.
See [alert runbooks](runbooks.md#alert-runbooks).
