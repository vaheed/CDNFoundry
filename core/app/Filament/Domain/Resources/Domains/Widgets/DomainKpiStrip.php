<?php

namespace App\Filament\Domain\Resources\Domains\Widgets;

use App\Models\Domain;
use App\Models\Operation;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class DomainKpiStrip extends Widget
{
    public ?Model $record = null;

    /** What the page last showed; when background work moves it, the page is told to refresh. */
    public ?string $fingerprint = null;

    protected string $view = 'filament.domain.widgets.domain-kpi-strip';

    protected int|string|array $columnSpan = 'full';

    #[On('domain-refreshed')]
    public function refreshStrip(): void
    {
        $this->fingerprint = $this->currentFingerprint();
    }

    /** Polled only while the domain has unfinished operations. */
    public function pollDomain(): void
    {
        $current = $this->currentFingerprint();
        if ($this->fingerprint !== null && $current !== $this->fingerprint) {
            $this->dispatch('domain-updated');
        }
        $this->fingerprint = $current;
    }

    public function hasPendingWork(): bool
    {
        return Operation::query()->where('domain_id', $this->record?->getKey())->whereIn('status', ['pending', 'running'])->exists();
    }

    private function currentFingerprint(): string
    {
        /** @var Domain $domain */
        $domain = $this->record;

        return implode('|', [
            $domain->updated_at?->getTimestamp(), $domain->revision, $domain->active_edge_revision,
            Operation::query()->where('domain_id', $domain->getKey())->max('updated_at'),
            $domain->dnsRecords()->max('updated_at'), $domain->tlsCertificates()->max('updated_at'),
        ]);
    }

    protected function getViewData(): array
    {
        /** @var Domain $domain */
        $domain = $this->record;
        $this->fingerprint ??= $this->currentFingerprint();
        $records = $domain->dnsRecords();
        $certificate = $domain->activeTlsCertificate;

        return ['pending' => $this->hasPendingWork(), 'items' => [
            ['label' => 'DNS records', 'value' => number_format((clone $records)->count())],
            ['label' => 'Proxied', 'value' => number_format((clone $records)->where('mode', 'proxied')->count())],
            ['label' => 'Revision', 'value' => '#'.$domain->revision],
            ['label' => 'Edge revision', 'value' => $domain->active_edge_revision === null ? 'None' : '#'.$domain->active_edge_revision],
            ['label' => 'Certificate', 'value' => $certificate?->expires_at === null ? 'None' : $certificate->expires_at->toDateString(), 'note' => $certificate?->expires_at === null ? null : 'expires '.$certificate->expires_at->diffForHumans(['parts' => 1])],
        ]];
    }
}
