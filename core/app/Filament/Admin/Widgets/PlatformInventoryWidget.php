<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\PlatformDnsSetting;
use App\Models\TlsCertificate;
use Filament\Widgets\Widget;

/** What the platform serves right now, counted from PostgreSQL desired state. */
class PlatformInventoryWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.platform-inventory';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    protected function getViewData(): array
    {
        $domains = Domain::query()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN lifecycle_state = 'active' THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN waf_profile <> 'off' THEN 1 ELSE 0 END) AS protected")->firstOrFail();
        $records = DnsRecord::query()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN mode = 'proxied' THEN 1 ELSE 0 END) AS proxied")->firstOrFail();
        $certificates = TlsCertificate::query()->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN status = 'active' AND expires_at < ? THEN 1 ELSE 0 END) AS expiring, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed", [now()->addDays(30)])->firstOrFail();
        $cells = EdgeCell::query()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'ready' AND drained = false THEN 1 ELSE 0 END) AS ready")->firstOrFail();
        $pools = EdgePool::query()->selectRaw('SUM(CASE WHEN enabled = true THEN 1 ELSE 0 END) AS enabled, SUM(CASE WHEN enabled = true AND waf_capable = true THEN 1 ELSE 0 END) AS waf_capable')->firstOrFail();
        $edges = Edge::query()->selectRaw('COUNT(*) AS total, COUNT(DISTINCT country_code) AS countries')->firstOrFail();
        $identity = PlatformDnsSetting::query()->find(1);

        $total = (int) $domains->getAttribute('total');
        $proxied = (int) $records->getAttribute('proxied');
        $expiring = (int) $certificates->getAttribute('expiring');
        $failedCertificates = (int) $certificates->getAttribute('failed');
        $readyCells = (int) $cells->getAttribute('ready');
        $protected = (int) $domains->getAttribute('protected');

        return [
            'items' => [
                ['label' => 'Domains', 'value' => number_format($total), 'note' => number_format((int) $domains->getAttribute('active')).' active', 'url' => DomainResource::getUrl(panel: 'admin')],
                ['label' => 'Proxied hostnames', 'value' => number_format($proxied), 'note' => number_format((int) $records->getAttribute('total') - $proxied).' DNS only'],
                ['label' => 'Certificates', 'value' => number_format((int) $certificates->getAttribute('active')), 'note' => $failedCertificates > 0 ? "{$failedCertificates} failed" : ($expiring > 0 ? "{$expiring} expire within 30 days" : 'None expire within 30 days'), 'tone' => $failedCertificates > 0 ? 'danger' : ($expiring > 0 ? 'warning' : 'success')],
                ['label' => 'Ready cells', 'value' => number_format($readyCells), 'note' => 'of '.number_format((int) $cells->getAttribute('total')).' across '.number_format((int) $edges->getAttribute('total')).' PoPs', 'tone' => (int) $cells->getAttribute('total') > 0 && $readyCells === (int) $cells->getAttribute('total') ? 'success' : 'warning', 'url' => EdgePoolResource::getUrl(panel: 'admin')],
                ['label' => 'WAF protected domains', 'value' => number_format($protected), 'note' => number_format((int) $pools->getAttribute('waf_capable')).' WAF-capable pools'],
            ],
            'facts' => array_filter([
                'Release' => config('release.version'),
                'Platform domain' => $identity?->platform_domain,
                'Proxy hostname' => $identity?->proxy_hostname,
                'Countries with a PoP' => number_format((int) $edges->getAttribute('countries')),
            ], fn (mixed $value): bool => filled($value)),
        ];
    }
}
