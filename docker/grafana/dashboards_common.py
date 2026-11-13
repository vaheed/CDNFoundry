"""Query fragments shared by the dashboard definitions."""
from __future__ import annotations

from dashboard_lib import SeriesSQL, traffic_sql

S = 'region=~"$region",pop=~"$pop",edge=~"$edge"'
SP = 'region=~"$region",pop=~"$pop"'


def req(extra: str = "") -> str:
    return f"cdnf:edge_requests:rate5m{{{S}{extra}}}"


RPS = f"sum({req()})"
BYTES = f"8 * sum(cdnf:edge_bytes_sent:rate5m{{{S}}})"
ERR_PCT = f'100 * sum({req(",status_class=\"5xx\"")}) / sum({req()})'
HIT_PCT = f'100 * sum({req(",cache_status=\"HIT\"")}) / sum({req()})'
P95_MS = f"1000 * histogram_quantile(0.95, sum by (le) (cdnf:edge_latency_bucket:rate5m{{{S}}}))"
P50_MS = f"1000 * histogram_quantile(0.5, sum by (le) (cdnf:edge_latency_bucket:rate5m{{{S}}}))"
ORIGIN_P95_MS = f"1000 * histogram_quantile(0.95, sum by (le) (cdnf:edge_origin_latency_bucket:rate5m{{{S}}}))"
DNS_QPS = f"sum(cdnf:dns_queries:rate5m{{{SP}}})"
FIRING = 'count(ALERTS{alertstate="firing",alertname!="Watchdog"}) or vector(0)'

# ClickHouse: a share of requests, a quantile, and a rate, over the rollup that fits the selected range.
CH_REQUESTS = "sum(requests)"
CH_ERR_PCT = "100 * sumIf(requests, status >= 500) / nullIf(sum(requests), 0)"
CH_HIT_PCT = "100 * sumIf(requests, cache_status = 'HIT') / nullIf(sum(requests), 0)"
CH_P95 = "quantilesTDigestMerge(0.5, 0.95, 0.99)(edge_latency_q)[2]"
CH_ORIGIN_P95 = "quantilesTDigestMerge(0.5, 0.95, 0.99)(origin_latency_q)[2]"
CH_TTFB_P95 = "quantilesTDigestMerge(0.5, 0.95, 0.99)(ttfb_q)[2]"
CH_BYTES = "sum(bytes_sent)"


def top(dim: str, measure: str, alias: str, *, limit: int = 10, where: str = "", hostname: bool = False, coarse: bool = False) -> str:
    """The largest values of a dimension over the selected range."""
    return traffic_sql(f"{measure} AS {alias}", dims=dim, where=where, order=f"{alias} DESC", limit=limit, hostname=hostname, coarse=coarse)


def split(dim: str, measure: str, alias: str, *, where: str = "", hostname: bool = False, coarse: bool = False, per_second: bool = False) -> SeriesSQL:
    """One series per value of a dimension with few values (PoP, protocol, IP version, status class)."""
    if " AS " not in dim:
        # Events without the dimension (a request refused before it was parsed) still need a series name.
        dim = f"if({dim} = '', 'not recorded', {dim}) AS {dim}"
    sql = SeriesSQL(traffic_sql(f"{measure} AS {alias}", dims=dim, time=True, where=where, hostname=hostname, coarse=coarse, per_second=per_second))
    sql.label = dim.split(" AS ")[-1]
    return sql


# The standard variables as a ClickHouse filter, for queries written by hand over a rollup's columns.
SCOPE = "$__conditionalAll(region IN (${region:singlequote}), $region) AND $__conditionalAll(pop IN (${pop:singlequote}), $pop) AND $__conditionalAll(edge_id IN (${edge:singlequote}), $edge) AND $__conditionalAll(domain_id IN (${domain:csv}), $domain)"
