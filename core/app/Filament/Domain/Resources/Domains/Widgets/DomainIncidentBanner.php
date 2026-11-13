<?php

namespace App\Filament\Domain\Resources\Domains\Widgets;

use App\Models\Domain;
use App\Models\User;
use App\Support\IncidentProfile;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/** Shown only while under attack mode (the incident profile) is on: what it turns on, who started it, and when it ends. */
class DomainIncidentBanner extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.domain.widgets.domain-incident-banner';

    protected int|string|array $columnSpan = 'full';

    #[On('domain-refreshed')]
    public function refreshBanner(): void {}

    protected function getViewData(): array
    {
        /** @var Domain $domain */
        $domain = $this->record?->refresh();

        return ['active' => IncidentProfile::active($domain), 'domain' => $domain, 'protections' => IncidentProfile::protections(),
            'starter' => $domain->incident_started_by === null ? null : User::query()->whereKey($domain->incident_started_by)->value('name')];
    }
}
