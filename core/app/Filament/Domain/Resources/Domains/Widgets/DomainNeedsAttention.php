<?php

namespace App\Filament\Domain\Resources\Domains\Widgets;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/** Lists only what is wrong with one domain; renders nothing when everything is fine. */
class DomainNeedsAttention extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.domain.widgets.domain-needs-attention';

    protected int|string|array $columnSpan = 'full';

    #[On('domain-refreshed')]
    public function refreshProblems(): void {}

    protected function getViewData(): array
    {
        /** @var Domain $domain */
        $domain = $this->record;
        $problems = [];
        if ($domain->nameservers_verified_at === null) {
            $problems[] = ['text' => 'Nameservers are not verified', 'fix' => 'Set the assigned nameservers at your registrar, then verify.'];
        }
        if ($domain->delegation_lost_at !== null && $domain->lifecycle_state === DomainLifecycleState::PendingVerification) {
            $problems[] = ['text' => 'Nameservers changed, so the domain is unverified', 'fix' => 'Set '.implode(' and ', $domain->assignedNameservers()).' at your registrar. It verifies on its own at the next check.'];
        }
        $expiry = $domain->registrationExpiry();
        if ($expiry !== null && $expiry['tone'] !== 'neutral') {
            $problems[] = ['text' => 'Registration '.($expiry['days'] < 0 ? 'has expired' : 'expires soon').': '.strtolower($expiry['label']), 'fix' => 'Renew the domain at your registrar.'];
        }
        $failedDeployments = $domain->dnsDeployments()->whereNotNull('last_error')->count();
        if ($failedDeployments > 0 && auth()->user()?->isAdmin()) {
            $problems[] = ['text' => $failedDeployments === 1 ? 'A DNS deployment failed' : "{$failedDeployments} DNS deployments failed", 'fix' => 'Choose Reconcile in the DNS deployment section below.'];
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
