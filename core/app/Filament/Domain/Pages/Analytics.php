<?php

namespace App\Filament\Domain\Pages;

use App\Filament\Domain\Widgets\AnalyticsBreakdowns;
use App\Filament\Domain\Widgets\AnalyticsDomainTable;
use App\Filament\Domain\Widgets\AnalyticsRecentLogs;
use App\Filament\Domain\Widgets\AnalyticsResponsesChart;
use App\Filament\Domain\Widgets\AnalyticsSecurityBreakdowns;
use App\Filament\Domain\Widgets\AnalyticsSummary;
use App\Filament\Domain\Widgets\AnalyticsTrafficChart;
use App\Filament\Domain\Widgets\AnalyticsUsage;
use App\Filament\Domain\Widgets\Concerns\AnalyticsFilterOptions;
use App\Support\AnalyticsStore;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Analytics across every domain assigned to the user, narrowed by date range and domains. */
class Analytics extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'analytics';

    protected static ?string $title = 'Analytics';

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-chart-bar';

    protected static ?string $navigationLabel = 'Analytics and logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Observe';

    protected static ?int $navigationSort = 10;

    public function mount(): void
    {
        // Links that name one domain (?domain=ID) open the page narrowed to it.
        $domain = request()->integer('domain');
        if ($domain > 0 && blank($this->filters['domains'] ?? null)) {
            $this->filters = [...($this->filters ?? []), 'domains' => [(string) $domain]];
            $this->getFiltersForm()->fill($this->filters);
        }
    }

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function getSubheading(): ?string
    {
        return AnalyticsFilterOptions::assignedDomains()->exists() ? null : 'No domains are assigned to your account yet.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->compact()->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->extraAttributes(['data-testid' => 'analytics-filters'])
                ->schema([
                    Select::make('range')->label('Time range')->options(AnalyticsFilterOptions::RANGES)
                        ->default('24h')->selectablePlaceholder(false)->native(false),
                    DatePicker::make('from')->label('From')->native(false)->displayFormat('Y-m-d')
                        ->minDate(now()->subDays(AnalyticsFilterOptions::HOURLY_RETENTION_DAYS))->maxDate(now())
                        ->visible(fn (Get $get): bool => $get('range') === 'custom'),
                    DatePicker::make('to')->label('To')->native(false)->displayFormat('Y-m-d')
                        ->minDate(now()->subDays(AnalyticsFilterOptions::HOURLY_RETENTION_DAYS))->maxDate(now())
                        ->visible(fn (Get $get): bool => $get('range') === 'custom'),
                    Select::make('domains')->label('Domains')->placeholder('All domains')->multiple()->searchable()->native(false)
                        ->maxItems(AnalyticsStore::MAX_SCOPE_DOMAINS)
                        ->options(fn (): array => AnalyticsFilterOptions::assignedDomains()->orderBy('name')->limit(50)->pluck('name', 'domains.id')->all())
                        ->getSearchResultsUsing(fn (string $search): array => AnalyticsFilterOptions::assignedDomains()->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(50)->pluck('name', 'domains.id')->all())
                        ->getOptionLabelsUsing(fn (array $values): array => AnalyticsFilterOptions::assignedDomains()->whereKey($values)->pluck('name', 'domains.id')->all())
                        ->columnSpan(fn (Get $get): int => $get('range') === 'custom' ? 1 : 3),
                ]),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            AnalyticsSummary::class,
            AnalyticsTrafficChart::class,
            AnalyticsResponsesChart::class,
            AnalyticsDomainTable::class,
            AnalyticsBreakdowns::class,
            AnalyticsSecurityBreakdowns::class,
            AnalyticsRecentLogs::class,
            AnalyticsUsage::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'xl' => 2];
    }
}
