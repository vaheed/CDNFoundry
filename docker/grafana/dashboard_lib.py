"""Building blocks for the provisioned Grafana dashboards.

Dashboards are defined in `dashboards.py` with these helpers and written to `dashboards/<folder>/<uid>.json`
by `build_dashboards.py`. The style contract in tests/observability/test_grafana_contract.py is checked on the
written JSON, so a rule broken here fails CI.
"""
from __future__ import annotations

import copy
from typing import Any

DOCS = "https://vaheed.github.io/CDNFoundry/operations/runbooks"

PROM = {"type": "prometheus", "uid": "prometheus"}
CH = {"type": "grafana-clickhouse-datasource", "uid": "clickhouse"}
PG = {"type": "postgres", "uid": "control-db"}
LOKI = {"type": "loki", "uid": "loki"}

# One shared set of thresholds. A panel names the set it uses; the JSON carries the steps.
NEUTRAL = "text"
THRESHOLDS: dict[str, list[dict[str, Any]]] = {
    "neutral": [{"color": NEUTRAL, "value": None}],
    "health": [{"color": "red", "value": None}, {"color": "green", "value": 1}],
    "zero_good": [{"color": "green", "value": None}, {"color": "red", "value": 1}],
    "zero_warn": [{"color": "green", "value": None}, {"color": "orange", "value": 1}],
    "error_percent": [{"color": "green", "value": None}, {"color": "orange", "value": 1}, {"color": "red", "value": 5}],
    "latency_ms": [{"color": "green", "value": None}, {"color": "orange", "value": 300}, {"color": "red", "value": 800}],
    "latency_s": [{"color": "green", "value": None}, {"color": "orange", "value": 0.3}, {"color": "red", "value": 0.8}],
    "utilization": [{"color": "green", "value": None}, {"color": "orange", "value": 70}, {"color": "red", "value": 90}],
    "ratio_utilization": [{"color": "green", "value": None}, {"color": "orange", "value": 0.7}, {"color": "red", "value": 0.9}],
    "hit_percent": [{"color": "orange", "value": None}, {"color": "green", "value": 60}],
    "age_seconds": [{"color": "green", "value": None}, {"color": "orange", "value": 120}, {"color": "red", "value": 600}],
    "flood_ratio": [{"color": "green", "value": None}, {"color": "orange", "value": 2}, {"color": "red", "value": 4}],
    "budget": [{"color": "red", "value": None}, {"color": "orange", "value": 0.25}, {"color": "green", "value": 0.5}],
}

FOLDERS = [
    ("00-overview", "00 Overview", "cdnf-folder-overview"),
    ("10-noc", "10 NOC", "cdnf-folder-noc"),
    ("20-soc", "20 SOC", "cdnf-folder-soc"),
    ("30-platform-admin", "30 Platform Admin", "cdnf-folder-platform"),
    ("40-on-call-slo", "40 On-call & SLO", "cdnf-folder-oncall"),
    ("50-domains", "50 Domains", "cdnf-folder-domains"),
    ("60-business-capacity", "60 Business & Capacity", "cdnf-folder-business"),
    ("90-samples", "90 Samples", "cdnf-folder-samples"),
]

# Table choice by selected range: 1-minute rollup up to 48 hours, hourly up to 400 days, daily beyond.
ROLLUPS = [("edge_traffic_1m", 0, 172800), ("edge_traffic_hourly", 172801, 34560000), ("edge_traffic_daily", 34560001, 10**10)]
# Hostname, TLS protocol, and cipher exist only in the hourly and daily rollups.
COARSE_ROLLUPS = [("edge_traffic_hourly", 0, 34560000), ("edge_traffic_daily", 34560001, 10**10)]


def _clickhouse_scope(table: str, hostname: bool) -> str:
    parts = [
        "$__conditionalAll(region IN (${region:singlequote}), $region)",
        "$__conditionalAll(pop IN (${pop:singlequote}), $pop)",
        "$__conditionalAll(edge_id IN (${edge:singlequote}), $edge)",
        "$__conditionalAll(domain_id IN (${domain:csv}), $domain)",
    ]
    if hostname and table != "edge_traffic_1m":
        parts.append("$__conditionalAll(hostname IN (${hostname:singlequote}), $hostname)")
    return " AND ".join(parts)


def traffic_sql(select: str, *, dims: str = "", time: bool = False, where: str = "", hostname: bool = False, coarse: bool = False, order: str = "", limit: int | None = None) -> str:
    """One query over the rollup that matches the selected range. Only one of the three branches returns rows."""
    branches = []
    for table, low, high in (COARSE_ROLLUPS if hostname or coarse else ROLLUPS):
        columns = ([ "$__timeInterval(interval_start) AS time" ] if time else []) + ([dims] if dims else []) + [select]
        dim_group = dims.split(" AS ")[-1] if dims else ""
        group = ", ".join((["time"] if time else []) + ([dim_group] if dim_group else []))
        # A bucket is stamped with its start, so a short range inside an hour needs the bucket that holds it.
        bucket = {"edge_traffic_1m": "interval_start >= $__fromTime - INTERVAL 1 MINUTE AND interval_start <= $__toTime",
                  "edge_traffic_hourly": "interval_start >= toStartOfHour($__fromTime) AND interval_start <= $__toTime",
                  "edge_traffic_daily": "interval_start >= toStartOfDay($__fromTime) AND interval_start <= $__toTime"}[table]
        branches.append(
            f"SELECT {', '.join(columns)} FROM cdnf.{table} WHERE {bucket} "
            f"AND dateDiff('second', $__fromTime, $__toTime) BETWEEN {low} AND {high} AND {_clickhouse_scope(table, hostname)}"
            + (f" AND {where}" if where else "") + (f" GROUP BY {group}" if group else "")
        )
    sql = " UNION ALL ".join(branches)
    if order or limit:
        sql = f"SELECT * FROM ({sql})" + (f" ORDER BY {order}" if order else "") + (f" LIMIT {limit}" if limit else "")
    elif time:
        sql = f"SELECT * FROM ({sql}) ORDER BY time"
    return sql


# --- targets ---------------------------------------------------------------------------------------------

def prom(expr: str, legend: str, *, instant: bool = False, ref: str = "A", fmt: str | None = None) -> dict[str, Any]:
    target: dict[str, Any] = {"datasource": PROM, "expr": expr, "legendFormat": legend, "refId": ref, "range": not instant, "instant": instant}
    if fmt:
        target["format"] = fmt
    return target


def ch(sql: str, *, table: bool = False, ref: str = "A") -> dict[str, Any]:
    return {"datasource": CH, "editorType": "sql", "format": 1 if table else 0, "queryType": "table" if table else "timeSeries", "rawSql": sql, "refId": ref}


def pg(sql: str, *, table: bool = True, ref: str = "A") -> dict[str, Any]:
    return {"datasource": PG, "editorMode": "code", "format": "table" if table else "time_series", "rawQuery": True, "rawSql": sql, "refId": ref}


def loki(expr: str, *, ref: str = "A", instant: bool = False) -> dict[str, Any]:
    return {"datasource": LOKI, "expr": expr, "queryType": "instant" if instant else "range", "refId": ref}


def refs(targets: list[dict[str, Any]]) -> list[dict[str, Any]]:
    for index, target in enumerate(targets):
        target["refId"] = chr(ord("A") + index)
    return targets


# --- panels ----------------------------------------------------------------------------------------------

def _source(targets: list[dict[str, Any]]) -> dict[str, Any]:
    uids = {target["datasource"]["uid"] for target in targets}
    return copy.deepcopy(PROM) if uids == {"prometheus"} else (copy.deepcopy(next(t for t in targets)["datasource"]) if len(uids) == 1 else {"type": "datasource", "uid": "-- Mixed --"})


def _defaults(unit: str, threshold: str, decimals: int | None, min_value: float | None = None, max_value: float | None = None) -> dict[str, Any]:
    defaults: dict[str, Any] = {
        "unit": unit,
        "color": {"mode": "thresholds"},
        "thresholds": {"mode": "absolute", "steps": copy.deepcopy(THRESHOLDS[threshold])},
        "noValue": "No traffic" if unit in {"reqps", "Bps", "bps", "short", "none"} else "–",
        "mappings": [{"type": "special", "options": {"match": "null+nan", "result": {"text": "No data yet", "color": NEUTRAL}}}],
    }
    if decimals is not None:
        defaults["decimals"] = decimals
    if min_value is not None:
        defaults["min"] = min_value
    if max_value is not None:
        defaults["max"] = max_value
    return defaults


def stat(title: str, description: str, targets: list[dict[str, Any]], unit: str = "short", threshold: str = "neutral", *, w: int = 4, h: int = 4, decimals: int | None = None, text: bool = False, link: str | None = None) -> dict[str, Any]:
    panel = {
        "type": "stat", "title": title, "description": description, "datasource": _source(targets), "targets": refs(targets),
        "gridPos": {"w": w, "h": h, "x": 0, "y": 0},
        "fieldConfig": {"defaults": _defaults(unit, threshold, decimals), "overrides": []},
        "options": {"colorMode": "value", "graphMode": "none", "justifyMode": "center", "textMode": "value", "wideLayout": True,
                    "reduceOptions": {"calcs": ["lastNotNull"], "fields": "/^[^_]/" if text else "", "values": False}},
    }
    if link:
        panel["fieldConfig"]["defaults"]["links"] = [{"title": "Open details", "url": link}]
    return panel


def timeseries(title: str, description: str, targets: list[dict[str, Any]], unit: str = "short", *, w: int = 12, h: int = 8, stack: bool = False, threshold: str = "neutral", decimals: int | None = None, fill: int = 12, min_value: float | None = None, max_value: float | None = None) -> dict[str, Any]:
    defaults = _defaults(unit, threshold, decimals, min_value, max_value)
    defaults["color"] = {"mode": "palette-classic"}
    defaults["custom"] = {"drawStyle": "line", "lineWidth": 1, "fillOpacity": fill, "showPoints": "never", "spanNulls": True,
                          "stacking": {"mode": "normal" if stack else "none", "group": "A"}, "thresholdsStyle": {"mode": "off"}}
    return {
        "type": "timeseries", "title": title, "description": description, "datasource": _source(targets), "targets": refs(targets),
        "gridPos": {"w": w, "h": h, "x": 0, "y": 0},
        "fieldConfig": {"defaults": defaults, "overrides": []},
        "options": {"legend": {"displayMode": "list", "placement": "bottom", "showLegend": True, "calcs": []}, "tooltip": {"mode": "multi", "sort": "desc"}},
    }


def table(title: str, description: str, targets: list[dict[str, Any]], unit: str = "short", *, w: int = 12, h: int = 8, overrides: list[dict[str, Any]] | None = None, transformations: list[dict[str, Any]] | None = None, sort: str | None = None) -> dict[str, Any]:
    defaults = _defaults(unit, "neutral", None)
    defaults["noValue"] = "–"
    defaults["custom"] = {"align": "auto", "cellOptions": {"type": "auto"}, "inspect": False}
    panel = {
        "type": "table", "title": title, "description": description, "datasource": _source(targets), "targets": refs(targets),
        "gridPos": {"w": w, "h": h, "x": 0, "y": 0},
        "fieldConfig": {"defaults": defaults, "overrides": overrides or []},
        "options": {"showHeader": True, "cellHeight": "sm", "footer": {"show": False}, **({"sortBy": [{"displayName": sort, "desc": True}]} if sort else {})},
    }
    if transformations:
        panel["transformations"] = transformations
    return panel


def bargauge(title: str, description: str, targets: list[dict[str, Any]], unit: str = "short", threshold: str = "neutral", *, w: int = 12, h: int = 8, max_value: float | None = None, decimals: int | None = None) -> dict[str, Any]:
    return {
        "type": "bargauge", "title": title, "description": description, "datasource": _source(targets), "targets": refs(targets),
        "gridPos": {"w": w, "h": h, "x": 0, "y": 0},
        "fieldConfig": {"defaults": {**_defaults(unit, threshold, decimals, 0, max_value), "color": {"mode": "continuous-BlPu" if threshold == "neutral" else "thresholds"}}, "overrides": []},
        "options": {"displayMode": "gradient", "orientation": "horizontal", "showUnfilled": True, "namePlacement": "left", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": True}},
    }


def piechart(title: str, description: str, targets: list[dict[str, Any]], unit: str = "short", *, w: int = 8, h: int = 8) -> dict[str, Any]:
    defaults = _defaults(unit, "neutral", None)
    defaults["color"] = {"mode": "palette-classic"}
    return {
        "type": "piechart", "title": title, "description": description, "datasource": _source(targets), "targets": refs(targets),
        "gridPos": {"w": w, "h": h, "x": 0, "y": 0}, "fieldConfig": {"defaults": defaults, "overrides": []},
        "options": {"pieType": "donut", "legend": {"displayMode": "table", "placement": "right", "showLegend": True, "values": ["value"]}, "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": True}, "tooltip": {"mode": "single"}},
    }


def geomap(title: str, description: str, targets: list[dict[str, Any]], *, w: int = 12, h: int = 10) -> dict[str, Any]:
    return {
        "type": "geomap", "title": title, "description": description, "datasource": _source(targets), "targets": refs(targets),
        "gridPos": {"w": w, "h": h, "x": 0, "y": 0},
        "fieldConfig": {"defaults": {**_defaults("short", "health", None), "unit": "short", "links": [{"title": "Open PoP detail", "url": "/d/cdnf-pop-detail?var-pop=${__data.fields.pop}"}]}, "overrides": []},
        "options": {"view": {"id": "zero", "lat": 20, "lon": 10, "zoom": 1.2, "allLayers": True}, "controls": {"showZoom": True, "mouseWheelZoom": False},
                    "basemap": {"type": "default", "name": "Basemap", "config": {}},
                    "layers": [{"type": "markers", "name": "PoPs", "location": {"mode": "lookup", "lookup": "country", "gazetteer": "public/gazetteer/countries.json"},
                                "config": {"showLegend": True, "style": {"size": {"fixed": 14, "min": 8, "max": 24}, "color": {"field": "healthy", "fixed": "green"}, "opacity": 0.8}}}]},
    }


def logs(title: str, description: str, targets: list[dict[str, Any]], *, w: int = 24, h: int = 10) -> dict[str, Any]:
    return {
        "type": "logs", "title": title, "description": description, "datasource": _source(targets), "targets": refs(targets),
        "gridPos": {"w": w, "h": h, "x": 0, "y": 0}, "fieldConfig": {"defaults": {"unit": "none", "thresholds": {"mode": "absolute", "steps": copy.deepcopy(THRESHOLDS["neutral"])}}, "overrides": []},
        "options": {"showTime": True, "wrapLogMessage": True, "enableLogDetails": True, "sortOrder": "Descending", "dedupStrategy": "none", "prettifyLogMessage": False},
    }


def text(title: str, description: str, content: str, *, w: int = 24, h: int = 3) -> dict[str, Any]:
    return {"type": "text", "title": title, "description": description, "gridPos": {"w": w, "h": h, "x": 0, "y": 0},
            "options": {"mode": "markdown", "content": content}, "fieldConfig": {"defaults": {"unit": "none", "thresholds": {"mode": "absolute", "steps": copy.deepcopy(THRESHOLDS["neutral"])}}, "overrides": []}}


LIBRARY: dict[str, dict[str, Any]] = {}


def shared(uid: str, panel: dict[str, Any], *, w: int | None = None, h: int | None = None) -> dict[str, Any]:
    """Register a panel in Grafana's library and return a reference to it, so a fix changes every dashboard that uses it.

    The model is written to library-panels/<uid>.json and created by the bootstrap job. A dashboard keeps only
    the reference, a title for the layout, and where the panel sits.
    """
    model = copy.deepcopy(panel)
    model.pop("gridPos", None)
    model.pop("id", None)
    if uid in LIBRARY and LIBRARY[uid] != model:
        raise ValueError(f"library panel {uid} is defined twice with different content")
    LIBRARY[uid] = model
    return {"id": 0, "type": panel["type"], "title": panel["title"], "libraryPanel": {"uid": uid, "name": panel["title"]},
            "gridPos": {"w": w or panel["gridPos"]["w"], "h": h or panel["gridPos"]["h"], "x": 0, "y": 0}}


def row(title: str) -> dict[str, Any]:
    return {"type": "row", "title": title, "collapsed": False, "gridPos": {"w": 24, "h": 1, "x": 0, "y": 0}, "panels": []}


# --- variables --------------------------------------------------------------------------------------------

def _query_var(name: str, label: str, datasource: dict[str, Any], query: str, *, sql: bool = False) -> dict[str, Any]:
    variable: dict[str, Any] = {
        "name": name, "label": label, "type": "query", "datasource": datasource, "query": query, "definition": query if sql else "",
        "includeAll": True, "multi": True, "current": {"selected": True, "text": ["All"], "value": ["$__all"]}, "refresh": 2, "sort": 1,
        "hide": 0, "options": [], "regex": "", "skipUrlSync": False,
    }
    if not sql:
        variable["query"] = {"query": query, "refId": f"var-{name}", "qryType": 1}
        variable["definition"] = query
        variable["allValue"] = ".*"
    return variable


def variables() -> list[dict[str, Any]]:
    """The seven standard variables, in their one fixed order. Every default is All, which aggregates all PoPs."""
    environment = {
        "name": "environment", "label": "Environment", "type": "custom", "query": "production,staging,development", "includeAll": True, "multi": True,
        "current": {"selected": True, "text": ["All"], "value": ["$__all"]}, "allValue": ".*", "hide": 0, "skipUrlSync": False,
        "options": [{"selected": True, "text": "All", "value": "$__all"}] + [{"selected": False, "text": value, "value": value} for value in ("production", "staging", "development")],
    }
    return [
        environment,
        _query_var("region", "Region", PROM, "label_values(cdnf:edge_requests:rate5m, region)"),
        _query_var("pop", "PoP", PROM, 'label_values(cdnf:edge_requests:rate5m{region=~"$region"}, pop)'),
        _query_var("edge", "Edge", PROM, 'label_values(cdnf:edge_requests:rate5m{pop=~"$pop"}, edge)'),
        _query_var("pool", "Pool", PROM, "label_values(cdnfoundry_cell_runtime_state, pool)"),
        _query_var("domain", "Domain", PG, "SELECT id AS __value, name AS __text FROM domains WHERE deleted_at IS NULL ORDER BY name LIMIT 10000", sql=True),
        _query_var("hostname", "Hostname", CH, "SELECT DISTINCT hostname FROM cdnf.edge_traffic_hourly WHERE interval_start > now() - INTERVAL 7 DAY AND hostname != '' ORDER BY hostname LIMIT 2000", sql=True),
    ]


def annotations() -> list[dict[str, Any]]:
    def sql(name: str, color: str, query: str) -> dict[str, Any]:
        return {"name": name, "enable": True, "iconColor": color, "datasource": PG, "target": {"editorMode": "code", "format": "table", "rawQuery": True, "rawSql": query, "refId": "Anno"}}

    return [
        {"builtIn": 1, "datasource": {"type": "grafana", "uid": "-- Grafana --"}, "enable": True, "hide": True, "iconColor": "rgba(0, 211, 255, 1)", "name": "Annotations & Alerts", "type": "dashboard"},
        sql("Fleet rollouts", "blue", "SELECT created_at AS time, 'Fleet rollout ' || status AS text, 'rollout' AS tags FROM grafana_fleet_rollouts WHERE $__timeFilter(created_at) ORDER BY created_at LIMIT 200"),
        sql("Artifact publishes", "purple", "SELECT date_trunc('minute', created_at) AS time, 'Edge artifact published: ' || kind AS text, 'artifact' AS tags FROM grafana_edge_artifacts WHERE $__timeFilter(created_at) GROUP BY 1, kind ORDER BY 1 LIMIT 200"),
        sql("Purges", "orange", "SELECT created_at AS time, 'Cache purge ' || type AS text, 'purge' AS tags FROM grafana_cache_purges WHERE $__timeFilter(created_at) ORDER BY created_at LIMIT 200"),
        sql("Settings changes", "yellow", "SELECT created_at AS time, action AS text, 'settings' AS tags FROM grafana_audit_events WHERE $__timeFilter(created_at) AND (action LIKE 'settings.%' OR action LIKE 'platform.%') ORDER BY created_at LIMIT 200"),
        sql("Emergency mode", "red", "SELECT created_at AS time, 'Emergency mode on for ' || target_type AS text, 'emergency' AS tags FROM grafana_emergency_modes WHERE $__timeFilter(created_at) ORDER BY created_at LIMIT 200"),
        {"name": "Critical alerts", "enable": True, "iconColor": "dark-red", "datasource": PROM, "expr": 'ALERTS{alertstate="firing",severity="critical"}', "step": "60s", "titleFormat": "{{alertname}}", "tagKeys": "team,alertname", "textFormat": "{{summary}}"},
    ]


# --- dashboards -------------------------------------------------------------------------------------------

CHARS_PER_COLUMN = 3.4


def fit_titles(row_panels: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Give each panel in a row the columns its title needs, taking them from the panels that have room to spare."""
    panels = [copy.deepcopy(panel) for panel in row_panels]
    needed = [max(4, -(-int(len(panel["title"]) / CHARS_PER_COLUMN * 10) // 10)) if panel["type"] != "row" else panel["gridPos"]["w"] for panel in panels]
    widths = [panel["gridPos"]["w"] for panel in panels]
    for _ in range(40):
        short = [i for i, (w, n) in enumerate(zip(widths, needed)) if w < n]
        if not short:
            break
        donors = [i for i, (w, n) in enumerate(zip(widths, needed)) if w > max(n, 4)]
        if not donors:
            break
        widths[short[0]] += 1
        widths[max(donors, key=lambda i: widths[i] - needed[i])] -= 1
    for panel, width in zip(panels, widths):
        panel["gridPos"]["w"] = width
    return panels


def layout(rows: list[Any]) -> list[dict[str, Any]]:
    """Lay panels out left to right. A list is one row of panels sharing the 24 columns; a dict is a section header."""
    panels: list[dict[str, Any]] = []
    y = 0
    for item in rows:
        if isinstance(item, dict):
            item = copy.deepcopy(item)
            item["gridPos"]["y"] = y
            panels.append(item)
            y += 1
            continue
        height = max(panel["gridPos"]["h"] for panel in item)
        item = fit_titles(item)
        used = sum(panel["gridPos"]["w"] for panel in item)
        if used != 24:
            raise ValueError(f"panels in a row must fill 24 columns, got {used}: {[p['title'] for p in item]}")
        x = 0
        for panel in item:
            panel = copy.deepcopy(panel)
            panel["gridPos"].update({"x": x, "y": y, "h": height})
            x += panel["gridPos"]["w"]
            panels.append(panel)
        y += height
    for index, panel in enumerate(panels, start=1):
        panel["id"] = index
    return panels


def dashboard(uid: str, title: str, folder_tag: str, description: str, rows: list[Any], *, runbook: str, parent: tuple[str, str] | None = ("cdnf-now", "Now"),
              refresh: str = "30s", time_from: str = "now-6h", tags: list[str] | None = None, kiosk: bool = False, extra_variables: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    links: list[dict[str, Any]] = []
    if parent is not None:
        links.append({"type": "link", "title": f"Parent: {parent[1]}", "url": f"/d/{parent[0]}", "icon": "arrow-up", "targetBlank": False, "keepTime": True, "asDropdown": False, "includeVars": True, "tags": []})
    links.append({"type": "dashboards", "title": "Siblings", "tags": [folder_tag], "asDropdown": True, "includeVars": True, "keepTime": True, "icon": "external link", "url": "", "targetBlank": False})
    links.append({"type": "link", "title": "Runbook", "url": f"{DOCS}#{runbook}", "icon": "doc", "targetBlank": True, "keepTime": False, "asDropdown": False, "includeVars": False, "tags": []})
    template = variables() + (extra_variables or [])
    return {
        "uid": uid, "title": title, "description": description, "tags": ["cdnfoundry", folder_tag] + (tags or []),
        "schemaVersion": 42, "version": 1, "editable": False, "graphTooltip": 1, "fiscalYearStartMonth": 0, "weekStart": "monday", "timezone": "browser",
        "refresh": refresh, "time": {"from": time_from, "to": "now"},
        "timepicker": {"refresh_intervals": ["10s", "30s", "1m", "5m", "15m"], "hidden": False},
        "annotations": {"list": annotations()}, "templating": {"list": template}, "links": links, "panels": layout(rows),
    }
