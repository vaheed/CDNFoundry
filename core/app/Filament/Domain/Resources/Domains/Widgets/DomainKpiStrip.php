<?php

namespace App\Filament\Domain\Resources\Domains\Widgets;

use App\Models\Domain;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class DomainKpiStrip extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected ?string $pollingInterval = '30s';

    protected int|array|null $columns = ['default' => 2, 'xl' => 5];

    protected function getStats(): array
    {
        /** @var Domain $domain */
        $domain = $this->record;
        $records = $domain->dnsRecords();
        $certificate = $domain->activeTlsCertificate;

        return [
            Stat::make('Records', number_format((clone $records)->count())),
            Stat::make('Proxied', number_format((clone $records)->where('mode', 'proxied')->count())),
            Stat::make('Revision', '#'.$domain->revision),
            Stat::make('Edge revision', $domain->active_edge_revision === null ? 'None' : '#'.$domain->active_edge_revision),
            Stat::make('Certificate', $certificate?->expires_at === null ? 'None' : $certificate->expires_at->diffForHumans(['parts' => 1, 'short' => true]))
                ->description($certificate?->expires_at?->toDateString()),
        ];
    }
}
