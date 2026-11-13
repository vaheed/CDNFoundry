<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use App\Support\AnalyticsStore;
use App\Support\GeoVocabulary;
use Filament\Widgets\Widget;
use Throwable;

/** Every request of the chosen domains, mitigated or not, by attack score band, action, country, network, path, and user agent. */
class AnalyticsSecurityBreakdowns extends Widget
{
    use UsesAnalyticsFilters;

    public const TABS = ['bands' => 'Attack score', 'actions' => 'Actions', 'countries' => 'Countries', 'asns' => 'Networks', 'paths' => 'Paths', 'agents' => 'User agents'];

    /** Score bands as the cell reports them: 0 is a request that was not scored. */
    public const BANDS = [0 => 'Not scored', 1 => '1 to 19: attack', 2 => '20 to 39: likely attack', 3 => '40 to 59: suspicious', 4 => '60 to 79: probably clean', 5 => '80 to 99: clean'];

    private const ACTIONS = ['allowed' => 'Allowed', 'blocked' => 'Blocked', 'logged' => 'Logged by a rule'];

    protected string $view = 'filament.domain.widgets.analytics.breakdowns';

    protected int|string|array $columnSpan = 'full';

    public string $tab = 'bands';

    public int $limit = 10;

    public function selectTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $this->tab = $tab;
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
        $tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'bands';
        $base = ['tabs' => self::TABS, 'active' => $tab, 'columns' => [], 'rows' => [], 'hasMore' => false, 'note' => null, 'heading' => 'Security', 'prefix' => 'analytics-security', 'sectionId' => 'analytics-security'];
        $scope = $this->analyticsScope();
        if ($scope['domains'] === []) {
            return [...$base, 'state' => 'no_data'];
        }
        $columns = ['label' => ucfirst(strtolower(self::TABS[$tab])), 'requests' => 'Requests', 'mitigated' => 'Mitigated', 'share' => 'Mitigated share'];
        $store = app(AnalyticsStore::class);
        $note = null;
        try {
            $rows = match ($tab) {
                'bands' => $this->rows($store->aggregate($scope['domains'], $this->analyticsRange(), 'security-bands'), fn (array $row): string => self::BANDS[(int) $row['attack_band']] ?? 'Unknown'),
                'actions' => $this->rows($store->aggregate($scope['domains'], $this->analyticsRange(), 'security-actions'), fn (array $row): string => self::ACTIONS[$row['action']] ?? (string) $row['action']),
                'countries' => $this->rows($store->aggregate($scope['domains'], $this->analyticsRange(), 'security-countries'), fn (array $row): string => GeoVocabulary::countryName($row['country'] ?? null)),
                'asns' => $this->rows($store->aggregate($scope['domains'], $this->analyticsRange(), 'security-asns'), fn (array $row): string => (int) $row['asn'] === 0 ? 'Unknown network' : 'AS'.$row['asn']),
                'paths' => $this->rawRows($store, $scope['domains'], 'security-paths', fn (array $row): string => (string) $row['path']),
                'agents' => $this->rawRows($store, $scope['domains'], 'security-agents', fn (array $row): string => (string) ($row['user_agent'] ?: 'No user agent')),
            };
        } catch (Throwable) {
            return [...$base, 'state' => 'unavailable'];
        }
        if (in_array($tab, ['paths', 'agents'], true)) {
            $note = 'Paths and user agents cover the last 24 hours of the range, most mitigated first; request details are kept for 7 days.';
        }
        if ($tab === 'bands') {
            $note = 'The attack score runs from 1 (attack) to 99 (clean). Not scored means the managed WAF was off for the request.';
        }

        return [...$base, 'state' => $rows === [] ? 'no_data' : 'ready', 'columns' => $columns, 'rows' => array_slice($rows, 0, $this->limit), 'hasMore' => count($rows) > $this->limit, 'note' => $note];
    }

    /** @param list<array<string, mixed>> $rows */
    private function rawRows(AnalyticsStore $store, array $domains, string $view, callable $label): array
    {
        $raw = $this->analyticsRawRange();

        return $raw === null ? [] : $this->rows($store->aggregate($domains, $raw, $view), $label);
    }

    /** @param list<array<string, mixed>> $rows */
    private function rows(array $rows, callable $label): array
    {
        return array_map(fn (array $row): array => [
            'label' => $label($row), 'requests' => number_format((int) $row['requests']), 'mitigated' => number_format((int) $row['mitigated']),
            'share' => (int) $row['requests'] === 0 ? '0.0%' : number_format(100 * (int) $row['mitigated'] / (int) $row['requests'], 1).'%',
        ], $rows);
    }
}
