<?php

namespace App\Filament\Domain\Pages;

use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Models\DnsRecord;
use App\Models\Domain;
use Filament\Pages\Dashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DomainDashboard extends Dashboard
{
    protected string $view = 'filament.domain.pages.dashboard';

    public function getSubheading(): ?string
    {
        return 'Authoritative DNS and edge delivery for your assigned domains.';
    }

    public function getSummaryProperty(): array
    {
        $state = $this->domains()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN lifecycle_state = 'active' THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN nameservers_verified_at IS NULL THEN 1 ELSE 0 END) AS pending_delegation")->firstOrFail();
        $total = (int) $state->total;
        $active = (int) $state->active;
        $pendingDelegation = (int) $state->pending_delegation;
        $proxied = DnsRecord::query()->where('mode', 'proxied')
            ->whereHas('domain.users', fn (Builder $users): Builder => $users->where('users.id', auth()->id()))
            ->count();
        $url = DomainResource::getUrl();

        return [
            ['label' => 'Assigned domains', 'value' => $total, 'description' => 'Domains available to this account', 'tone' => $total > 0 ? 'primary' : 'neutral', 'url' => $url],
            ['label' => 'Active domains', 'value' => $active, 'description' => 'Serving authoritative DNS', 'tone' => $active > 0 ? 'success' : 'neutral', 'url' => $url],
            ['label' => 'Pending delegation', 'value' => $pendingDelegation, 'description' => 'Nameserver verification required', 'tone' => $pendingDelegation > 0 ? 'warning' : 'success', 'url' => $url],
            ['label' => 'Proxied hostnames', 'value' => $proxied, 'description' => 'Hostnames assigned to the edge', 'tone' => $proxied > 0 ? 'success' : 'neutral', 'url' => $url],
        ];
    }

    public function getRecentDomainsProperty(): Collection
    {
        return $this->domains()->withCount([
            'dnsRecords',
            'dnsRecords as proxied_records_count' => fn (Builder $records): Builder => $records->where('mode', 'proxied'),
        ])->latest('updated_at')->limit(6)->get();
    }

    private function domains(): Builder
    {
        return Domain::query()->whereHas('users', fn (Builder $users): Builder => $users->where('users.id', auth()->id()));
    }
}
