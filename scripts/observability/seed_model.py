"""The deterministic traffic model behind `make dev-observability-seed`.

Every value is a closed-form function of time and the active scenarios, so the exporter that serves "now" and the
backfill that writes the last two days agree exactly, and a rerun produces the same data.
"""
from __future__ import annotations

import math

DAY = 86400.0
EPOCH = 1_767_225_600.0  # 2026-01-01T00:00:00Z; counters count from here.

POPS = [
    {"pop": "seed-fra", "region": "eu", "edge": "00000000-0000-4000-8000-0000000000a1", "country": "DE", "rps": 40.0},
    {"pop": "seed-iad", "region": "us", "edge": "00000000-0000-4000-8000-0000000000a2", "country": "US", "rps": 30.0},
    {"pop": "seed-sin", "region": "apac", "edge": "00000000-0000-4000-8000-0000000000a3", "country": "SG", "rps": 20.0},
]
DOMAINS = list(range(9001, 9009))
STATUS = {"2xx": 0.93, "3xx": 0.04, "4xx": 0.027, "5xx": 0.003}
CACHE = {"HIT": 0.72, "MISS": 0.2, "EXPIRED": 0.03, "BYPASS": 0.03, "STALE": 0.01, "NONE": 0.01}
BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0]
EDGE_CDF = [0.08, 0.2, 0.42, 0.6, 0.78, 0.92, 0.975, 0.993, 0.998, 1.0, 1.0]
ORIGIN_CDF = [0.0, 0.02, 0.1, 0.25, 0.45, 0.7, 0.88, 0.96, 0.99, 1.0, 1.0]
BYTES_PER_REQUEST = 48_000.0
DNS_RCODE = {"NOERROR": 0.86, "NXDOMAIN": 0.12, "SERVFAIL": 0.0004, "REFUSED": 0.0196, "FORMERR": 0.0, "NOTIMP": 0.0}
SECURITY = {"waf": 0.05, "block": 0.2, "cache_abuse_detected": 0.004}
TLS_REASONS = {"unknown_sni": 0.02, "handshake_rate_exceeded": 0.0, "tls_disabled": 0.0, "certificate_unavailable": 0.001, "certificate_expired": 0.0, "certificate_invalid": 0.0}
SCENARIOS = ("normal", "origin-down", "l7-flood", "dns-flood", "pop-loss", "cert-expiry", "purge-stuck", "telemetry-drop")


def integral(rate: float, t: float) -> float:
    """Requests from EPOCH to t at `rate` per second with a daily wave of plus or minus 40 percent."""
    x = t - EPOCH
    return rate * (x + 0.4 * DAY / (2 * math.pi) * (1 - math.cos(2 * math.pi * x / DAY)))


def window(t: float, start: float, end: float) -> float:
    """Seconds of [start, end] that have passed at time t."""
    return max(0.0, min(t, end) - start)


class Scenarios:
    """The scenarios that are active, each as a window [start, end] in seconds since the Unix epoch."""

    def __init__(self, active: dict[str, tuple[float, float]] | None = None) -> None:
        self.active = active or {}

    def span(self, name: str) -> tuple[float, float]:
        return self.active.get(name, (0.0, 0.0))

    def on(self, name: str, t: float) -> bool:
        start, end = self.span(name)
        return start <= t <= end


def served(pop: dict, rate: float, t: float, scenarios: Scenarios) -> float:
    """Counter integral for a PoP, frozen while the pop-loss scenario holds that PoP's traffic at zero."""
    start, end = scenarios.span("pop-loss")
    if pop["pop"] != "seed-sin" or not end or t <= start:
        return integral(rate, t)
    if t <= end:
        return integral(rate, start)
    return integral(rate, start) + integral(rate, t) - integral(rate, end)


def requests_total(pop: dict, status: str, cache: str, t: float, scenarios: Scenarios) -> float:
    """Counter value of cdnf_edge_requests_total for one PoP, status class, and cache status."""
    value = served(pop, pop["rps"], t, scenarios) * STATUS[status] * CACHE[cache]
    start, end = scenarios.span("l7-flood")
    if pop["pop"] == "seed-fra" and end:
        value += 29 * pop["rps"] * window(t, start, end) * (0.9 if status == "2xx" else 0.1 / 3) * (0.1 if cache == "HIT" else 0.9 / 5)
    start, end = scenarios.span("origin-down")
    if end and status == "5xx":
        value += 6.0 * window(t, start, end) * (0.5 if cache == "MISS" else 0.5 / 5)
    return max(0.0, value)


def total_requests(pop: dict, t: float, scenarios: Scenarios) -> float:
    return sum(requests_total(pop, status, cache, t, scenarios) for status in STATUS for cache in CACHE)


def dns_queries_total(pop: dict, rcode: str, outcome: str, t: float, scenarios: Scenarios) -> float:
    rate = pop["rps"] * 0.6
    share = DNS_RCODE[rcode] * (0.9995 if outcome == "answered" else 0.0005)
    value = integral(rate, t) * share
    start, end = scenarios.span("dns-flood")
    if end:
        flood = 20 * rate * window(t, start, end)
        if rcode == "NXDOMAIN" and outcome == "answered":
            value += flood * 0.85
        elif rcode == "NOERROR" and outcome == "answered":
            value += flood * 0.15
        elif outcome == "response_limit" and rcode == "NOERROR":
            value += flood * 0.01
    return value


def security_total(pop: dict, klass: str, t: float, scenarios: Scenarios) -> float:
    value = integral(SECURITY[klass] * pop["rps"] / 40, t)
    start, end = scenarios.span("l7-flood")
    if end and pop["pop"] == "seed-fra" and klass == "waf":
        value += 15.0 * window(t, start, end)
    return value


def tls_total(pop: dict, reason: str, t: float, scenarios: Scenarios) -> float:
    value = integral(TLS_REASONS[reason] * pop["rps"] / 40, t)
    start, end = scenarios.span("cert-expiry")
    if end and reason == "certificate_expired" and pop["pop"] == "seed-iad":
        value += 0.5 * window(t, start, end)
    return value


def histogram(cdf: list[float], total: float) -> list[float]:
    return [total * fraction for fraction in cdf]
