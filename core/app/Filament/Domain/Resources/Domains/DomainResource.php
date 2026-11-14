<?php

namespace App\Filament\Domain\Resources\Domains;

use App\Filament\Domain\Resources\Domains\Pages\CreateDomain;
use App\Filament\Domain\Resources\Domains\Pages\ListDomains;
use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\ClientScriptsRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\DnsRecordsRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\HistoryRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\LogPushRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityEventsRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityRulesRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\UsersRelationManager;
use App\Filament\Domain\Resources\Domains\RelationManagers\WafExclusionsRelationManager;
use App\Models\Domain;
use App\Support\PlatformSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-globe';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' ? 'Customers' : 'Domains';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Domain')->placeholder('example.com')->required()->maxLength(253)->visibleOn('create'),
            TextInput::make('display_name')->label('Display label')->maxLength(253)->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->extraCellAttributes(['class' => 'cdn-link-cell']),
            TextColumn::make('lifecycle_state')->label('Status')
                ->formatStateUsing(fn ($state): string => ucfirst(str_replace('_', ' ', $state instanceof \BackedEnum ? $state->value : (string) $state)))
                ->badge()
                ->color(fn ($state): string => ($state instanceof \BackedEnum ? $state->value : (string) $state) === 'active' ? 'success' : 'warning'),
            TextColumn::make('nameservers')->label('Nameservers')
                ->state(fn (Domain $record): string => $record->nameservers_verified_at ? 'Verified' : 'Pending')
                ->badge()
                ->color(fn (string $state): string => $state === 'Verified' ? 'success' : 'warning'),
            TextColumn::make('registration_expires_at')->label('Expires')->sortable()
                ->state(fn (Domain $record): string => $record->registrationExpiry()['label'] ?? 'Unknown')
                ->badge()
                ->color(fn (Domain $record): string => match ($record->registrationExpiry()['tone'] ?? 'neutral') {
                    'danger' => 'danger',
                    'warning' => 'warning',
                    default => 'gray',
                })
                ->tooltip(fn (Domain $record): ?string => $record->registration_expires_at === null ? null : app(PlatformSettings::class)->formatTimestamp($record->registration_expires_at, 'Y-m-d')),
            TextColumn::make('dns_records_count')->label('Records')->counts('dnsRecords')->alignEnd()->fontFamily(FontFamily::Mono),
            TextColumn::make('revision')->sortable()->alignEnd()->fontFamily(FontFamily::Mono)->prefix('#'),
            TextColumn::make('updated_at')->label('Last change')->since()->sortable()->fontFamily(FontFamily::Mono)
                ->tooltip(fn (Domain $record): string => $record->updated_at->toDateTimeString()),
        ])->recordUrl(fn (Domain $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('id')
            ->searchPlaceholder('Search domains')
            ->emptyStateIcon('phosphor-globe')
            ->emptyStateHeading('No domains yet')
            ->emptyStateDescription('Add a domain to start serving authoritative DNS.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            View::make('filament.domain.overview'),
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
        return [DnsRecordsRelationManager::class, SecurityRulesRelationManager::class, SecurityEventsRelationManager::class, ClientScriptsRelationManager::class, WafExclusionsRelationManager::class, HistoryRelationManager::class, LogPushRelationManager::class, UsersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
            'create' => CreateDomain::route('/create'),
            'view' => ViewDomain::route('/{record}'),
        ];
    }
}
