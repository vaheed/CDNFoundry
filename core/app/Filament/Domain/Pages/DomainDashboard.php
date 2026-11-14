<?php

namespace App\Filament\Domain\Pages;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Pages\Analytics as AnalyticsPage;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\IncidentProfile;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * @property-read Collection<int, Domain> $domains
 *
 * The domain user's landing page: the traffic of their domains over the last 24 hours, what needs their action, and each
 * domain with its state. Traffic comes from the analytics store; when it does not answer, the rest of the page still shows.
 */
class DomainDashboard extends Dashboard
{
    protected string $view = 'filament.domain.pages.dashboard';

    /** Domains shown in the table and counted in the traffic figures. */
    private const LIMIT = AnalyticsStore::MAX_SCOPE_DOMAINS;

    /** @return Collection<int, Domain> */
    public function getDomainsProperty(): Collection
    {
        /** @var Collection<int, Domain> $domains */
        $domains = $this->domains()->with('activeTlsCertificate')->withCount([
            'dnsRecords',
            'dnsRecords as proxied_records_count' => fn (Builder $records): Builder => $records->where('mode', 'proxied'),
            'logPushDestinations as failing_destinations_count' => fn (Builder $destinations): Builder => $destinations->where('consecutive_failures', '>=', 3),
            'clientScriptSightings as new_scripts_count' => fn (Builder $scripts): Builder => $scripts->whereNull('approved_at'),
        ])->orderBy('name')->limit(self::LIMIT)->get();

        return $domains;
    }

    /** @return array{state: string, items: list<array<string, mixed>>, by_domain: array<int, array<string, mixed>>} */
    public function getTrafficProperty(): array
    {
        $domains = $this->domains->all();
        if ($domains === []) {
            return ['state' => 'empty', 'items' => [], 'by_domain' => []];
        }
        $now = CarbonImmutable::now('UTC');
        $range = ['from' => $now->subDay(), 'to' => $now, 'raw' => false, 'error' => null, 'bucket' => 'hour'];
        try {
            $store = app(AnalyticsStore::class);
            $summary = $store->summary($domains, $range);
            $byDomain = collect($store->aggregate($domains, $range, 'domains'))->keyBy(fn (array $row): int => (int) $row['domain_id'])->all();
        } catch (Throwable) {
            return ['state' => 'unavailable', 'items' => [], 'by_domain' => []];
        }
        $requests = (int) ($summary['requests'] ?? 0);
        $errors = (int) ($summary['origin_errors'] ?? 0);
        $blocked = (int) ($summary['security_blocks'] ?? 0);
        $url = AnalyticsPage::getUrl();

        return ['state' => 'ready', 'by_domain' => $byDomain, 'items' => [
            ['label' => 'Requests', 'value' => number_format($requests), 'url' => $url],
            ['label' => 'Bandwidth', 'value' => self::bytes(((int) ($summary['bytes_in'] ?? 0)) + ((int) ($summary['bytes_out'] ?? 0))), 'url' => $url],
            ['label' => 'Cache hit ratio', 'value' => number_format(((float) ($summary['cache_ratio'] ?? 0)) * 100, 1).'%', 'url' => $url],
            ['label' => 'Blocked requests', 'value' => number_format($blocked), 'url' => $url],
            ['label' => 'Origin errors', 'value' => number_format($errors), 'tone' => $errors > 0 ? 'warning' : 'neutral', 'url' => $url],
        ]];
    }

    /**
     * Only problems the user can act on, each with the page that fixes it, most urgent first.
     *
     * @return list<array{domain: string, text: string, fix: string, url: string, tone: string}>
     */
    public function getAttentionProperty(): array
    {
        $items = [];
        foreach ($this->domains as $domain) {
            $url = DomainResource::getUrl('view', ['record' => $domain]);
            // A closure, not an arrow function: an arrow function would add to a copy of the list.
            $add = function (string $text, string $fix, string $tone, string $tab = '') use (&$items, $domain, $url): void {
                $items[] = ['domain' => $domain->name, 'text' => $text, 'fix' => $fix, 'url' => $url.$tab, 'tone' => $tone];
            };
            if (IncidentProfile::active($domain)) {
                $add('Under attack mode is on', 'It ends '.$domain->incident_expires_at->diffForHumans().'. End it from the domain page once the attack is over.', 'danger');
            }
            if ($domain->nameservers_verified_at === null && $domain->lifecycle_state !== DomainLifecycleState::Deprovisioning) {
                $add('Nameservers are not verified', 'Set the assigned nameservers at your registrar; the domain is checked again on its own.', 'warning');
            }
            $certificate = $domain->activeTlsCertificate;
            if ($certificate?->last_error !== null) {
                $add('Certificate problem', (string) $certificate->last_error, 'danger');
            } elseif ($certificate?->expires_at !== null && $certificate->expires_at->isBefore(now()->addDays(14))) {
                $add('Certificate expires '.$certificate->expires_at->diffForHumans(), $domain->tls_mode === 'custom' ? 'Upload a renewed certificate.' : 'Renewal runs on its own; check the TLS section if it does not.', 'warning');
            }
            if ($domain->failing_destinations_count > 0) {
                $add('Log push is failing', 'A destination has refused several deliveries in a row. Open Log push to see why.', 'warning', '?activeRelationManager=6');
            }
            if ($domain->new_scripts_count > 0) {
                $add($domain->new_scripts_count.' new '.str('script')->plural($domain->new_scripts_count).' or connections to review', 'Approve the ones you expect in Scripts and connections.', 'warning', '?activeRelationManager=3');
            }
        }
        usort($items, fn (array $a, array $b): int => ($a['tone'] === 'danger' ? 0 : 1) <=> ($b['tone'] === 'danger' ? 0 : 1));

        return $items;
    }

    public function getTotalProperty(): int
    {
        return $this->domains()->count();
    }

    public static function bytes(int|float $value): string
    {
        $bytes = max(0, (float) $value);
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $index = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return number_format($bytes / (1024 ** $index), $index === 0 ? 0 : 1).' '.$units[$index];
    }

    private function domains(): Builder
    {
        return Domain::query()->whereHas('users', fn (Builder $users): Builder => $users->where('users.id', auth()->id()));
    }
}
