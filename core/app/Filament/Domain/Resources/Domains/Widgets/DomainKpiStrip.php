<?php

namespace App\Filament\Domain\Resources\Domains\Widgets;

use App\Models\Domain;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class DomainKpiStrip extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.domain.widgets.domain-kpi-strip';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        /** @var Domain $domain */
        $domain = $this->record;
        $records = $domain->dnsRecords();
        $certificate = $domain->activeTlsCertificate;

        return ['items' => [
            ['label' => 'DNS records', 'value' => number_format((clone $records)->count())],
            ['label' => 'Proxied', 'value' => number_format((clone $records)->where('mode', 'proxied')->count())],
            ['label' => 'Revision', 'value' => '#'.$domain->revision],
            ['label' => 'Edge revision', 'value' => $domain->active_edge_revision === null ? 'None' : '#'.$domain->active_edge_revision],
            ['label' => 'Certificate', 'value' => $certificate?->expires_at === null ? 'None' : $certificate->expires_at->toDateString(), 'note' => $certificate?->expires_at === null ? null : 'expires '.$certificate->expires_at->diffForHumans(['parts' => 1])],
        ]];
    }
}
