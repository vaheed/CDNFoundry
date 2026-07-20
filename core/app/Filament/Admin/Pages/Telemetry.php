<?php

namespace App\Filament\Admin\Pages;

use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Throwable;

class Telemetry extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Telemetry and usage';

    protected static string|\UnitEnum|null $navigationGroup = 'Observe';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.telemetry';

    public function getStateProperty(): array
    {
        $store = app(AnalyticsStore::class);
        $to = CarbonImmutable::now('UTC');
        $range = ['from' => $to->subDay(), 'to' => $to, 'raw' => false];
        try {
            return ['available' => true, 'meta' => $store->metadata($range), 'views' => [
                'Global summary' => [$store->summary(null, $range)],
                'Global traffic' => $store->aggregate(null, $range, 'traffic'),
                'Global DNS' => $store->aggregate(null, $range, 'dns'),
            ], 'buffer' => $this->bufferStatus()];
        } catch (Throwable) {
            return ['available' => false, 'meta' => ['from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(), 'partial' => true], 'views' => [], 'buffer' => $this->bufferStatus()];
        }
    }

    private function bufferStatus(): array
    {
        try {
            $metrics = Http::connectTimeout(1)->timeout(2)->get('http://vector:9598/metrics')->throw()->body();
            preg_match_all('/^(vector_buffer_[a-z_]+|vector_component_(?:discarded_events_total|errors_total))[^ ]* ([0-9.e+-]+)$/m', $metrics, $matches, PREG_SET_ORDER);

            return ['available' => true, 'metrics' => collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])->all()];
        } catch (Throwable) {
            return ['available' => false, 'metrics' => []];
        }
    }
}
