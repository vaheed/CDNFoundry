#!/usr/bin/env python3
"""Phase 8 stage 1: feasibility of shared client reputation, as a seeded model of the aggregation rule. No stack is needed.

This is NOT a measurement on real traffic. It runs the proposed rule against constructed traffic and attacker strategies so that the rule's
weak points show before anything is built. It can falsify the design; it cannot prove it safe for real visitors, and a pass here is not
evidence about real NAT populations (the lesson of Phase 7's missing corpus).

What a cell can report per heartbeat, bounded to the top K keys: requests seen, mitigated events by reason, and challenges solved. The control
plane adds the reports of a sliding window and decides per client key. The consequence is always "challenge", never "block".

Rule under test (parameters are the ones the experiment sweeps):
  * a key is a candidate when its window has >= MIN_EVENTS mitigated events and the events are >= MAX_CLEAN_SHARE complement of its traffic
    (offending share >= OFFENDING_SHARE), counting only reasons the platform defines (managed WAF, rate limit, challenge failure);
  * a key that solved a challenge in the window is exempt from the greylist for that window;
  * corroboration: events must come from >= CORROBORATION_EDGES distinct edges and >= CORROBORATION_DOMAINS distinct domains.

Questions: (1) time to a decision against an attacker on one domain through three edges, (2) NAT with mostly clean traffic, (3) a poisoning
tenant who makes visitors' browsers hit its own domain, (4) the harm if poisoning succeeds, (5) bounded state and retention.
"""

import json
import random
import statistics

WINDOW = 300            # seconds of sliding window
REPORT_INTERVAL = 60    # a cell's report reaches the control plane (assumed equal to the agents' measured 60 s refresh)
AGGREGATE_INTERVAL = 60  # the control plane publishes the dataset
DELIVERY_INTERVAL = 60  # an agent fetches it (measured 61.8 s for keys on the development stack)
K = 50                  # keys a cell reports
MIN_EVENTS = 100
OFFENDING_SHARE = 0.5
RETENTION_DAYS = 7
ENTRY_TTL = 900


def decide(window: dict, corroboration_edges: int, corroboration_domains: int) -> bool:
    events, requests = window["events"], window["requests"]
    if window["solved"] > 0 or events < MIN_EVENTS or requests == 0 or events / requests < OFFENDING_SHARE:
        return False
    return len(window["edges"]) >= corroboration_edges and len(window["domains"]) >= corroboration_domains


def attack_latency(trials: int = 400) -> dict:
    """One attacker, one domain, three edges, from the first event to a challenge on another domain's cell on a fourth edge."""
    rng = random.Random(8)
    latencies = []
    for _ in range(trials):
        offset = rng.uniform(0, REPORT_INTERVAL)
        events_per_second = 15  # about 900 a minute
        needed = MIN_EVENTS
        first_decision = offset + needed / events_per_second  # the report that contains enough events
        reported = (first_decision // REPORT_INTERVAL + 1) * REPORT_INTERVAL + rng.uniform(0, 1)
        aggregated = reported + rng.uniform(0, AGGREGATE_INTERVAL)
        delivered = aggregated + rng.uniform(0, DELIVERY_INTERVAL)
        latencies.append(delivered)
    latencies.sort()
    return {"p50_s": round(statistics.median(latencies)), "p95_s": round(latencies[int(len(latencies) * 0.95)]), "max_s": round(latencies[-1]), "budget_s": 300}


def nat_sweep() -> list[dict]:
    """One address, 20,000 requests in the window, varying the share that is attack. A solved challenge exempts the key."""
    out = []
    for share in (0.05, 0.2, 0.4, 0.5, 0.6, 0.9):
        requests = 20000
        window = {"events": int(requests * share), "requests": requests, "solved": 40, "edges": {"a", "b", "c"}, "domains": {"d1", "d2"}}
        no_solve = dict(window, solved=0)
        out.append({"attack_share": share, "greylisted_with_solves": decide(window, 3, 2), "greylisted_without_solves": decide(no_solve, 3, 2)})
    return out


def poisoning() -> list[dict]:
    """A tenant embeds hidden requests to its own domain in a page, so a victim's browser produces events there. Capability: how many edges
    and how many distinct domains/tenants the attacker can make the victim's requests reach."""
    results = []
    victim_clean = 400  # an ordinary person's requests in five minutes across the platform
    for edges, domains in ((1, 1), (3, 1), (3, 2), (3, 3)):
        window = {"events": 600, "requests": 600 + victim_clean, "solved": 0, "edges": {f"e{i}" for i in range(edges)}, "domains": {f"d{i}" for i in range(domains)}}
        results.append({"attacker_edges": edges, "attacker_domains": domains,
                        "greylisted_one_signal": decide(window, 1, 1), "greylisted_3_edges": decide(window, 3, 1),
                        "greylisted_3_edges_2_domains": decide(window, 3, 2)})
    return results


def harm_if_poisoned() -> dict:
    """The worst case after a successful poisoning: the victim is challenged, never blocked. Cost is one proof of work per domain for the
    life of a token (the solve time measured in Phase 7: p95 about 1.1 s at the default difficulty, 2.7 s on the slowest device)."""
    return {"consequence": "challenge", "blocked": False, "extra_seconds_per_domain_p95": 2.7, "ends_with": "a solved challenge exempts the key, or the entry TTL of %d s" % ENTRY_TTL}


def bounds() -> dict:
    """State the control plane keeps: at most K keys per cell per heartbeat, kept by window, then reduced to entries with a TTL."""
    cells, heartbeats_per_window = 200, WINDOW // REPORT_INTERVAL
    rows_in_window = cells * K * heartbeats_per_window
    bytes_per_row = 96  # key hash 16, tenant/domain/edge ids 24, counters 24, reasons 32
    return {"cells": cells, "keys_per_report": K, "rows_in_window": rows_in_window, "window_mb": round(rows_in_window * bytes_per_row / 1e6, 1),
            "retention_days": RETENTION_DAYS, "published_entries_cap": 50000, "entry_ttl_s": ENTRY_TTL}


def main() -> None:
    latency = attack_latency()
    assert latency["p95_s"] <= latency["budget_s"], latency
    nat = nat_sweep()
    # The NAT acceptance case: 5% attack is never greylisted, with or without a solve.
    assert not nat[0]["greylisted_with_solves"] and not nat[0]["greylisted_without_solves"]
    poison = poisoning()
    report = {"latency": latency, "nat": nat, "poisoning": poison, "harm": harm_if_poisoned(), "bounds": bounds()}
    print(json.dumps(report, indent=1))
    # Findings the experiment must show, so a change to the rule that loses them fails here.
    assert poison[0]["greylisted_one_signal"], "a single signal should be poisonable, or the experiment is not testing the weak case"
    assert not poison[0]["greylisted_3_edges"], "corroboration by edges did not stop a one-edge poisoner"
    print("reputation feasibility model completed")


if __name__ == "__main__":
    main()
