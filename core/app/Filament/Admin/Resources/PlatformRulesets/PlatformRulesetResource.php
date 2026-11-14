<?php

namespace App\Filament\Admin\Resources\PlatformRulesets;

use App\Filament\Admin\Resources\PlatformRulesets\Pages\CreatePlatformRuleset;
use App\Filament\Admin\Resources\PlatformRulesets\Pages\EditPlatformRuleset;
use App\Filament\Admin\Resources\PlatformRulesets\Pages\ListPlatformRulesets;
use App\Filament\Admin\Resources\PlatformRulesets\RelationManagers\RulesRelationManager;
use App\Models\Domain;
use App\Models\PlatformRuleset;
use App\Support\FilamentHelp;
use App\Support\PlatformRulesets;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlatformRulesetResource extends Resource
{
    protected static ?string $model = PlatformRuleset::class;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-shield-checkered';

    protected static string|\UnitEnum|null $navigationGroup = 'Operate';

    protected static ?string $navigationLabel = 'Platform rulesets';

    protected static ?string $modelLabel = 'platform ruleset';

    protected static ?string $pluralModelLabel = 'platform rulesets';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Name')->required()->maxLength(100)->unique(ignoreRecord: true)->placeholder('Known scanner paths'),
            TextInput::make('description')->label('Description')->maxLength(250)->placeholder('Blocks requests that probe for common admin pages')
                ->helperText('Domain owners see the name and this description, never the rules.'),
            Toggle::make('enabled')->label(FilamentHelp::label('Enabled', 'A disabled ruleset stays saved and stops applying to every domain.'))->default(true),
            Toggle::make('applies_to_all')->label(FilamentHelp::label('Apply to all domains', 'Every domain gets this ruleset ahead of its own rules. Turn it off to choose domains.'))->live()->default(false),
            Select::make('domain_ids')->label(FilamentHelp::label('Domains', 'Only these domains get the ruleset. Up to '.config('security.maximum_platform_ruleset_domains').'.'))
                ->multiple()->searchable()->maxItems((int) config('security.maximum_platform_ruleset_domains'))->placeholder('Search by domain name, for example example.com')
                ->getSearchResultsUsing(fn (string $search): array => Domain::query()->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                ->getOptionLabelsUsing(fn (array $values): array => Domain::query()->whereIn('id', $values)->pluck('name', 'id')->all())
                ->visible(fn (Get $get): bool => ! $get('applies_to_all')),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->description(fn (PlatformRuleset $record): ?string => $record->description)->wrap(),
            TextColumn::make('reach')->label('Applies to')->state(fn (PlatformRuleset $record): string => $record->applies_to_all ? 'All domains' : number_format($record->domains_count).' '.($record->domains_count === 1 ? 'domain' : 'domains')),
            TextColumn::make('rules_count')->label('Rules')->numeric()->alignEnd(),
            TextColumn::make('enabled')->label('State')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->modifyQueryUsing(fn ($query) => $query->withCount(['rules', 'domains']))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalHeading('Delete this ruleset?')->modalDescription('Every domain it reached stops getting its rules.')
                    ->using(fn (PlatformRuleset $record): bool => (bool) PlatformRulesets::deleteRuleset($record, auth()->user(), request()->ip())),
            ])->defaultSort('name')
            ->emptyStateHeading('No platform rulesets')
            ->emptyStateDescription('A ruleset holds custom and rate-limit rules that apply to all domains or to a list you choose, before each domain\'s own rules.');
    }

    public static function getRelations(): array
    {
        return [RulesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatformRulesets::route('/'), 'create' => CreatePlatformRuleset::route('/create'), 'edit' => EditPlatformRuleset::route('/{record}/edit')];
    }
}
