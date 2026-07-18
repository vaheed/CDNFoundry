<?php

namespace App\Filament\Domain\Resources\Domains;

use App\Filament\Domain\Resources\Domains\Pages\CreateDomain;
use App\Filament\Domain\Resources\Domains\Pages\ListDomains;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\DnsRecordsRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\UsersRelationManager;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\Operation;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

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
        return $schema->components([
            TextEntry::make('name')->label('Canonical domain'),
            TextEntry::make('display_name')->label('Display label'),
            TextEntry::make('lifecycle_state')->badge(),
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
                ->placeholder('None'),
            TextEntry::make('revision'),
            TextEntry::make('active_edge_revision')->label('Active edge revision')->placeholder('Not deployed'),
            TextEntry::make('proxy_hostnames')->label('Proxied hostnames')
                ->state(fn (Domain $record): int => $record->dnsRecords()->where('mode', 'proxied')->count()),
            TextEntry::make('edgePlacement.state')->label('Edge placement')->badge()->placeholder('Not placed'),
            TextEntry::make('edgePlacement.activePool.name')->label('Active service pool')->placeholder('None'),
            TextEntry::make('edgePlacement.targetPool.name')->label('Target service pool')->placeholder('None'),
            TextEntry::make('edgePlacement.last_error')->label('Edge deployment failure')->placeholder('None'),
            TextEntry::make('validated_edge_revisions')->label('Validated edge revisions')
                ->state(fn (Domain $record): string => EdgeRevision::query()->where('domain_id', $record->id)->where('status', 'validated')->latest('revision')->limit(10)->pluck('revision')->implode(', '))
                ->placeholder('None'),
            TextEntry::make('proxy_settings')->label('Proxy defaults')
                ->formatStateUsing(fn (?array $state): string => json_encode($state, JSON_UNESCAPED_SLASHES) ?: 'Defaults not customized')
                ->placeholder('Defaults not customized'),
            TextEntry::make('dnsDeployments.status')->label('Deployment states')->badge(),
            TextEntry::make('dnsDeployments.last_error')->label('Deployment errors')->placeholder('None'),
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
}
