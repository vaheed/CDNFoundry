<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use App\Support\AnalyticsStore;
use Filament\Widgets\Widget;
use Throwable;

/** Latest masked events for the scope, one stream at a time. */
class AnalyticsRecentLogs extends Widget
{
    use UsesAnalyticsFilters;

    public const STREAMS = ['requests' => 'Requests', 'errors' => 'Errors', 'security' => 'Security', 'dns' => 'DNS'];

    protected string $view = 'filament.domain.widgets.analytics.logs';

    protected int|string|array $columnSpan = 'full';

    public string $stream = 'requests';

    public int $limit = 10;

    public function selectStream(string $stream): void
    {
        abort_unless(array_key_exists($stream, self::STREAMS), 404);
        $this->stream = $stream;
        $this->limit = 10;
    }

    public function showMore(): void
    {
        $this->limit = min(100, $this->limit + 20);
    }

    public function showFewer(): void
    {
        $this->limit = 10;
    }

    protected function getViewData(): array
    {
        $stream = array_key_exists($this->stream, self::STREAMS) ? $this->stream : 'requests';
        $base = ['streams' => self::STREAMS, 'active' => $stream, 'rows' => [], 'hasMore' => false];
        $scope = $this->analyticsScope();
        $raw = $this->analyticsRawRange();
        if ($scope['domains'] === []) {
            return [...$base, 'state' => 'no_data'];
        }
        if ($raw === null) {
            return [...$base, 'state' => 'expired'];
        }
        try {
            $result = app(AnalyticsStore::class)->logs($scope['domains'], $raw, $stream, null);
        } catch (Throwable) {
            return [...$base, 'state' => 'unavailable'];
        }
        $items = $result['items'];

        return [
            ...$base,
            'state' => $items === [] ? 'no_data' : 'ready',
            'rows' => array_slice($items, 0, $this->limit),
            'hasMore' => count($items) > $this->limit,
        ];
    }

    /** One short reason for an error or security event, in words. */
    public function reason(array $row): ?string
    {
        foreach (['origin_error', 'tls_error', 'security_reason'] as $key) {
            if (filled($row[$key] ?? null)) {
                return str((string) $row[$key])->replace('_', ' ')->ucfirst()->toString();
            }
        }

        return filled($row['waf_action'] ?? null) && ($row['waf_action'] ?? '') !== 'pass' ? 'WAF '.$row['waf_action'] : null;
    }
}
