<?php

namespace App\Filament\Domain\Resources\Domains\Widgets;

use App\Models\Domain;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/** Lists only what is wrong with one domain; renders nothing when everything is fine. */
class DomainNeedsAttention extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.domain.widgets.domain-needs-attention';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        /** @var Domain $domain */
        $domain = $this->record;
        $problems = [];
        if ($domain->nameservers_verified_at === null) {
            $problems[] = ['text' => 'Nameservers are not verified', 'fix' => 'Set the assigned nameservers at your registrar, then verify.'];
        }
        $failedDeployments = $domain->dnsDeployments()->whereNotNull('last_error')->count();
        if ($failedDeployments > 0 && auth()->user()?->isAdmin()) {
            $problems[] = ['text' => "{$failedDeployments} DNS deployment(s) failed", 'fix' => 'Reconcile authoritative DNS from Domain actions.'];
        }
        $placementError = $domain->edgePlacement()->value('last_error');
        if (filled($placementError)) {
            $problems[] = ['text' => 'Edge deployment failed', 'fix' => (string) $placementError];
        }
        $certificateError = $domain->activeTlsCertificate?->last_error ?? $domain->latestTlsOrder?->last_error;
        if (filled($certificateError)) {
            $problems[] = ['text' => 'Certificate problem', 'fix' => (string) $certificateError];
        }

        return ['problems' => $problems];
    }
}
