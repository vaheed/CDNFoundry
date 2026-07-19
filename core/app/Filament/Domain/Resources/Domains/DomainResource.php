<?php

namespace App\Filament\Domain\Resources\Domains;

use App\Filament\Domain\Resources\Domains\Pages\CreateDomain;
use App\Filament\Domain\Resources\Domains\Pages\ListDomains;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\DnsRecordsRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\UsersRelationManager;
use App\Http\Controllers\CacheController;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\EdgeRoutingCompiler;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' ? 'Customers' : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Domain')->required()->maxLength(253)->visibleOn('create'),
            TextInput::make('display_name')->label('Display label')->maxLength(253)->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('lifecycle_state')->label('Lifecycle')->badge(),
            TextColumn::make('nameservers_verified_at')->label('Nameservers')->formatStateUsing(fn ($state): string => $state ? 'Verified' : 'Pending')->badge(),
            TextColumn::make('revision')->sortable(),
            TextColumn::make('updated_at')->label('Last change')->since()->sortable(),
        ])->recordUrl(fn (Domain $record): string => static::getUrl('view', ['record' => $record]))->defaultSort('id');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Domain status')
                ->description('Identity, lifecycle, and authoritative delegation state.')
                ->icon('heroicon-o-globe-alt')
                ->schema([
                    TextEntry::make('name')->label('Canonical domain')->copyable(),
                    TextEntry::make('display_name')->label('Display label')->placeholder('Same as canonical domain'),
                    TextEntry::make('lifecycle_state')->label('Lifecycle')->badge(),
                    TextEntry::make('revision')->label('Desired revision'),
                    TextEntry::make('nameservers_verified_at')->label('Nameservers verified')->dateTime()->placeholder('Pending'),
                    TextEntry::make('nameserver_verification_status')->label('Latest verification')
                        ->state(fn (Domain $record): ?string => $record->nameservers_verified_by !== null
                            ? 'force_verified'
                            : self::latestNameserverVerification($record)?->status)
                        ->badge()->placeholder('Not requested'),
                    TextEntry::make('nameserver_verification_error')->label('Verification error')
                        ->state(fn (Domain $record): ?string => $record->nameservers_verified_by !== null
                            ? null
                            : self::latestNameserverVerification($record)?->error)
                        ->placeholder('None')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
            Section::make('Edge delivery')
                ->description('A service pool is the bounded set of equivalent OpenResty cells and public addresses serving this domain. Normal domains use the shared pool; quarantine and dedicated pools provide deliberate isolation.')
                ->icon('heroicon-o-cloud')
                ->schema([
                    TextEntry::make('proxy_hostnames')->label('Proxied hostnames')
                        ->state(fn (Domain $record): int => $record->dnsRecords()->where('mode', 'proxied')->count()),
                    TextEntry::make('active_edge_revision')->label('Active edge revision')->placeholder('Not deployed'),
                    TextEntry::make('edgePlacement.state')->label('Placement state')->badge()->placeholder('Not placed'),
                    TextEntry::make('edgePlacement.activePool.name')->label('Active service pool')->placeholder('None'),
                    TextEntry::make('edgePlacement.targetPool.name')->label('Target service pool')->placeholder('None'),
                    TextEntry::make('service_pool_dns_target')->label('Service-pool DNS target')
                        ->state(function (Domain $record): ?string {
                            $placement = $record->edgePlacement()->with(['activePool', 'targetPool'])->first();
                            $pool = $placement?->state === 'draining' ? $placement->targetPool : $placement?->activePool;
                            $settings = PlatformDnsSetting::query()->find(1);

                            return $pool !== null && $settings !== null ? EdgeRoutingCompiler::poolHostname($settings, $pool) : null;
                        })->copyable()->placeholder('Waiting for placement'),
                    TextEntry::make('validated_edge_revisions')->label('Validated revisions')
                        ->state(fn (Domain $record): string => EdgeRevision::query()->where('domain_id', $record->id)->where('status', 'validated')->latest('revision')->limit(10)->pluck('revision')->implode(', '))
                        ->placeholder('None'),
                    TextEntry::make('proxy_settings_summary')->label('Proxy defaults')
                        ->state(fn (Domain $record): string => self::proxySettingsSummary($record->proxy_settings))
                        ->columnSpanFull(),
                    TextEntry::make('edgePlacement.last_error')->label('Last deployment failure')->placeholder('None')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
            Section::make('Authoritative DNS deployment')
                ->description('Per-cluster deployment acknowledgements and failures.')
                ->icon('heroicon-o-server-stack')
                ->schema([
                    TextEntry::make('dnsDeployments.status')->label('Deployment states')->badge()->placeholder('Not deployed'),
                    TextEntry::make('dnsDeployments.last_error')->label('Deployment errors')->placeholder('None'),
                ])->columns(['default' => 1, 'md' => 2])->collapsible(),
            Section::make('Cache')
                ->description('Desired cache policy, epoch-based invalidation, and temporary development bypass.')
                ->icon('heroicon-o-circle-stack')
                ->schema([
                    TextEntry::make('cache_policy')->label('Policy')->state(fn (Domain $record): string => self::cacheSettingsSummary($record)),
                    TextEntry::make('cache_epoch')->label('Full-purge epoch'),
                    TextEntry::make('cache_development_mode_until')->label('Development mode until')->dateTime()->placeholder('Off'),
                ])->columns(['default' => 1, 'md' => 3]),
            Section::make('TLS')
                ->description('Serving mode and the currently selected validated certificate. Private keys are never displayed.')
                ->icon('heroicon-o-lock-closed')
                ->schema([
                    TextEntry::make('tls_mode')->label('Mode')->badge(),
                    TextEntry::make('activeTlsCertificate.status')->label('Certificate status')->badge()->placeholder('Pending managed issuance'),
                    TextEntry::make('activeTlsCertificate.names')->label('Covered names')->listWithLineBreaks()->placeholder('None'),
                    TextEntry::make('activeTlsCertificate.expires_at')->label('Expires')->dateTime()->placeholder('None'),
                    TextEntry::make('activeTlsCertificate.fingerprint_sha256')->label('SHA-256 fingerprint')->copyable()->placeholder('None')->columnSpanFull(),
                    TextEntry::make('activeTlsCertificate.last_error')->label('Last failure')->placeholder('None')->columnSpanFull(),
                    TextEntry::make('latestTlsOrder.status')->label('Latest managed order')->badge()->placeholder('Not queued'),
                    TextEntry::make('latestTlsOrder.names')->label('Requested names')->listWithLineBreaks()->placeholder('None'),
                    TextEntry::make('latestTlsOrder.last_error')->label('ACME failure')->placeholder('None')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->orderBy('id');
        $user = auth()->user();

        return $user?->isAdmin() ? $query : $query->whereHas('users', fn (Builder $users) => $users->whereKey($user?->getKey()));
    }

    public static function getRelations(): array
    {
        return [DnsRecordsRelationManager::class, UsersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'view' => ViewDomain::route('/{record}'),
        ];
    }

    private static function latestNameserverVerification(Domain $domain): ?Operation
    {
        return Operation::query()->where('type', 'domain.nameservers_verify')
            ->where('input->domain_id', $domain->id)->latest()->first();
    }

    private static function proxySettingsSummary(mixed $settings): string
    {
        if (! is_array($settings)) {
            return 'Platform defaults';
        }

        $versions = collect($settings['http_versions'] ?? [])
            ->map(fn (mixed $version): string => 'HTTP/'.(string) $version)
            ->implode(' + ');

        return implode(' · ', [
            ($settings['enabled'] ?? true) ? 'Enabled' : 'Disabled',
            $versions !== '' ? $versions : 'No HTTP versions selected',
            ($settings['redirect_https'] ?? false) ? 'HTTPS redirect on' : 'HTTPS redirect off',
            (int) ($settings['retry_count'] ?? 0).' origin retries',
            is_array($settings['maintenance'] ?? null) ? 'Maintenance on' : 'Maintenance off',
        ]);
    }

    private static function cacheSettingsSummary(Domain $domain): string
    {
        $settings = $domain->cache_settings ?? CacheController::defaults();

        return implode(' · ', [
            $settings['enabled'] ? 'Enabled' : 'Disabled',
            'Edge '.(int) $settings['edge_ttl_seconds'].'s',
            'Browser '.(int) $settings['browser_ttl_seconds'].'s',
            number_format(((int) $settings['maximum_object_bytes']) / 1048576, 1).' MiB max',
            $settings['respect_origin_headers'] ? 'Origin headers respected' : 'Configured TTL enforced',
        ]);
    }
}
