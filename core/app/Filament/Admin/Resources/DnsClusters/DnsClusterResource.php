<?php

namespace App\Filament\Admin\Resources\DnsClusters;

use App\Filament\Admin\Resources\DnsClusters\Pages\CreateDnsCluster;
use App\Filament\Admin\Resources\DnsClusters\Pages\EditDnsCluster;
use App\Filament\Admin\Resources\DnsClusters\Pages\ListDnsClusters;
use App\Models\DnsCluster;
use App\Models\PlatformDnsSetting;
use App\Support\FilamentHelp;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DnsClusterResource extends Resource
{
    protected static ?string $model = DnsCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $navigationLabel = 'DNS clusters';

    protected static ?string $modelLabel = 'DNS cluster';

    protected static ?string $pluralModelLabel = 'DNS clusters';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cluster connection')
                ->description('Private PowerDNS API boundary and desired cluster state.')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(100)->unique(ignoreRecord: true),
                    TextInput::make('location')->label('Location')->required()->maxLength(100),
                    TextInput::make('api_url')->label('API URL')->url()->required()->maxLength(500),
                    TextInput::make('server_id')->label('Server ID')->required()->default('localhost')->maxLength(100),
                    TextInput::make('api_key')->label('API key')->password()->revealable()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state))->minLength(8),
                    TextInput::make('capacity_zones')->label('Zone capacity')->numeric()->required()->default(100000)->minValue(1)->maxValue(10000000),
                    Toggle::make('enabled')
                        ->label(FilamentHelp::label('Enabled', 'A new cluster stays disabled until its asynchronous connection test succeeds.'))
                        ->default(false)->disabled(fn (?DnsCluster $record): bool => $record === null || $record->last_health_status !== 'healthy'),
                ]),
            Section::make('Authoritative nameservers')
                ->description('Names published for zones assigned to this cluster.')
                ->schema([
                    Repeater::make('nameservers')
                        ->label(FilamentHelp::label('Nameservers', 'At least two authoritative nameservers are required for redundancy. These default to the System DNS identity nameservers.'))
                        ->addActionLabel('Add nameserver')
                        ->schema([
                            TextInput::make('hostname')->label('Hostname')->required()->maxLength(253),
                        ])->default(fn (): array => collect(PlatformDnsSetting::query()->find(1)?->nameservers ?? [])
                        ->map(fn (array $nameserver): array => ['hostname' => $nameserver['hostname']])->all())
                        ->minItems(2)->maxItems(8)->required(),
                ]),
            Section::make('Operator context')
                ->collapsed(fn (?DnsCluster $record): bool => blank($record?->operational_notes))
                ->schema([
                    Textarea::make('operational_notes')->label('Operational notes')->rows(4)->maxLength(4000),
                ]),
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
