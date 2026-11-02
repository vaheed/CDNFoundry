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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DnsClusterResource extends Resource
{
    protected static ?string $model = DnsCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-hard-drives';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $navigationLabel = 'DNS primary';

    protected static ?string $modelLabel = 'DNS primary';

    protected static ?string $pluralModelLabel = 'DNS primaries';

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
                    TextInput::make('transfer_endpoint')->label('Transfer endpoint')->required()->maxLength(100)->helperText('Where DNS nodes pull zones from: this primary’s public IP address and transfer port, such as 192.0.2.10:5300.')->regex('/^(?:\d{1,3}(?:\.\d{1,3}){3}|\[[0-9a-fA-F:]+\]):\d{1,5}$/'),
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
            TextColumn::make('enabled')->label('State')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            TextColumn::make('last_health_status')->label('Health')->badge()->formatStateUsing(fn ($state): string => ucfirst(str_replace('_', ' ', $state instanceof \BackedEnum ? $state->value : (string) $state)))->placeholder('Not tested')->color(fn (?string $state): string => match ($state) {
                'healthy' => 'success',
                'unhealthy' => 'danger',
                default => 'gray',
            }),
            TextColumn::make('last_health_at')->since(),
            TextColumn::make('last_reconciled_revision')->sortable(),
        ])->recordActions([EditAction::make()])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ListDnsClusters::route('/'), 'create' => CreateDnsCluster::route('/create'), 'edit' => EditDnsCluster::route('/{record}/edit')];
    }
}
