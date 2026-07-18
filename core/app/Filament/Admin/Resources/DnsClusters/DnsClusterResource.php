<?php

namespace App\Filament\Admin\Resources\DnsClusters;

use App\Filament\Admin\Resources\DnsClusters\Pages\CreateDnsCluster;
use App\Filament\Admin\Resources\DnsClusters\Pages\EditDnsCluster;
use App\Filament\Admin\Resources\DnsClusters\Pages\ListDnsClusters;
use App\Models\DnsCluster;
use App\Models\PlatformDnsSetting;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DnsClusterResource extends Resource
{
    protected static ?string $model = DnsCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Control plane';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            TextInput::make('location')->required()->maxLength(100),
            Toggle::make('enabled')->default(false)->disabled(fn (?DnsCluster $record): bool => $record === null || $record->last_health_status !== 'healthy')
                ->helperText('A new cluster stays disabled until its asynchronous connection test succeeds.'),
            TextInput::make('api_url')->url()->required()->maxLength(500),
            TextInput::make('api_key')->password()->revealable()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state))->minLength(8),
            TextInput::make('server_id')->required()->default('localhost')->maxLength(100),
            Repeater::make('nameservers')->schema([
                TextInput::make('hostname')->required()->maxLength(253),
            ])->default(fn (): array => collect(PlatformDnsSetting::query()->find(1)?->nameservers ?? [])
                ->map(fn (array $nameserver): array => ['hostname' => $nameserver['hostname']])->all())
                ->minItems(2)->maxItems(8)->required()
                ->helperText('At least two authoritative nameservers are required for redundancy. These default to the System DNS identity nameservers.'),
            TextInput::make('capacity_zones')->numeric()->required()->default(100000)->minValue(1)->maxValue(10000000),
            Textarea::make('operational_notes')->maxLength(4000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('location')->sortable(),
            IconColumn::make('enabled')->boolean(),
            TextColumn::make('last_health_status')->badge()->placeholder('Not tested'),
            TextColumn::make('last_health_at')->since(),
            TextColumn::make('last_reconciled_revision')->sortable(),
        ])->recordActions([EditAction::make()])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ListDnsClusters::route('/'), 'create' => CreateDnsCluster::route('/create'), 'edit' => EditDnsCluster::route('/{record}/edit')];
    }
}
