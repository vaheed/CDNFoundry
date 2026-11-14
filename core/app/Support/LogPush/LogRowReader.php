<?php

namespace App\Support\LogPush;

use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;

/** Reads the next rows of one domain's dataset from ClickHouse with the read-only telemetry user. */
class LogRowReader
{
    public function __construct(private readonly AnalyticsStore $store) {}

    /** @return list<array<string, mixed>> */
    public function after(string $dataset, int $domainId, string $afterAt, string $afterId, CarbonImmutable $until, int $limit): array
    {
        return $this->store->rowsAfter(LogDatasets::table($dataset), LogDatasets::condition($dataset), LogDatasets::fields($dataset), $domainId, $afterAt, $afterId, $until, $limit);
    }
}
