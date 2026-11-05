---
title: Usage and operations reports
description: Get the monthly usage report and the weekly operations report, build one on demand, and know what each contains.
---

# Usage and operations reports

CDNFoundry builds two reports for you on a schedule. Each comes as a CSV file and
a PDF. They are built from the stored rollups, not from raw logs, so they are
fast and match the Grafana dashboards.

**You need:** an administrator account or API token.

| Report | Covers | Built | Contains |
| --- | --- | --- | --- |
| **Usage** | One finished month | On the 2nd of each month at 03:40 UTC | Bandwidth (bytes sent, headers included), 95th-percentile bandwidth, requests, and DNS queries, per domain, pool, and region |
| **Operations** | One finished week | Mondays at 06:00 UTC | SLO results, incidents, top errors, top attacked domains, and the capacity forecast |

The usage report runs the same query as the **Usage and billing** dashboard, so
its totals equal that dashboard for the same range. See
[Grafana by team](grafana-by-team.md#business-and-capacity).

## Get a report

<DocTabs :labels="['API', 'Command line']">
<template #tab-1>

```sh
curl "$CDNF_URL/api/admin/reports" -H "Authorization: Bearer $CDNF_TOKEN"
curl -OJ "$CDNF_URL/api/admin/reports/$REPORT_ID/download" -H "Authorization: Bearer $CDNF_TOKEN"
```

The first call lists reports with their kind, period, format, and size. The
second downloads one.

</template>
<template #tab-2>

Run this on the control host, inside the `core` container:

```sh
docker compose --env-file .env.prod exec core php artisan cdnf:reports:build usage
```

The command builds the report for the last finished period, writes the CSV and
PDF, and records them so you can download them through the API.

</template>
</DocTabs>

**Expected result:** the list shows a row for each format of each period, and the
download is the file.

There is no screen for reports in the admin panel. Use the API.

## Build a report on demand

Use this to build a past period, or to rebuild one:

```sh
docker compose --env-file .env.prod exec core \
  php artisan cdnf:reports:build usage --period=2026-10
docker compose --env-file .env.prod exec core \
  php artisan cdnf:reports:build operations --period=2026-W44
```

- For `usage`, the period is a month as `YYYY-MM`.
- For `operations`, the period is a week as `YYYY-Www`.
- Without `--period`, the command uses the last finished period.

**If it fails:** the command says "Choose a month that has finished" or "Choose a
week that has finished". A report covers only a complete period. If rollups for
the period are missing, the command reports that the report could not be built.

## Keep them

Reports are stored on the control host's local storage and recorded in the
database, with a checksum. They are derived data: you can rebuild any report from
the rollups. Back up the control plane as described in
[Backup and recovery](backup-and-recovery.md).

See the [API conventions](../reference/api/index.md) for tokens and errors.
