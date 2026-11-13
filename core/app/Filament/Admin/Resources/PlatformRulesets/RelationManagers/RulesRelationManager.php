<?php

namespace App\Filament\Admin\Resources\PlatformRulesets\RelationManagers;

use App\Filament\Shared\SecurityRuleForm;
use App\Models\PlatformRulesetRule;
use App\Support\FilamentHelp;
use App\Support\PlatformRulesets;
use App\Support\SecurityRuleData;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rules';

    protected static ?string $title = 'Rules';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord->rules()->count());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(SecurityRuleForm::fields());
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('priority')->label(FilamentHelp::label('Order', 'Rules run from the lowest number up, after every other platform ruleset that comes first and before the domain\'s own rules.'))->sortable(),
            TextColumn::make('name')->label('Rule')
                ->state(fn (PlatformRulesetRule $record): string => $record->name ?? SecurityRuleData::describe($record->conditions))
                ->description(fn (PlatformRulesetRule $record): ?string => collect([$record->name === null ? null : SecurityRuleData::describe($record->conditions), $record->rate_limit === null ? null : 'Limit: '.SecurityRuleData::describeRateLimit($record->rate_limit, $record->action).'.'])->filter()->implode(' ') ?: null)->wrap(),
            TextColumn::make('action')->badge()
                ->formatStateUsing(fn (string $state): string => SecurityRuleData::ACTION_LABELS[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'block' => 'danger', 'challenge' => 'warning', 'allow' => 'success', default => 'gray',
                }),
            TextColumn::make('enabled')->label('State')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->headerActions([
            CreateAction::make()->label('Add rule')->modalHeading('Add rule')->modalSubmitActionLabel('Add rule')->slideOver()->modalWidth(Width::TwoExtraLarge)->createAnother(false)
                ->using(fn (array $data): PlatformRulesetRule => PlatformRulesets::saveRule($this->getOwnerRecord(), null, $data, auth()->user(), request()->ip())[0]),
        ])->recordActions([
            EditAction::make()->modalHeading('Edit rule')->modalSubmitActionLabel('Save rule')->slideOver()->modalWidth(Width::TwoExtraLarge)->using(fn (PlatformRulesetRule $record, array $data): PlatformRulesetRule => PlatformRulesets::saveRule($this->getOwnerRecord(), $record, $data, auth()->user(), request()->ip())[0]),
            DeleteAction::make()->using(fn (PlatformRulesetRule $record): bool => (bool) PlatformRulesets::deleteRule($this->getOwnerRecord(), $record, auth()->user(), request()->ip())),
        ])->defaultSort('priority')
            ->emptyStateHeading('No rules yet')
            ->emptyStateDescription('Add a rule to block, challenge, log, or rate limit requests on every domain this ruleset reaches.');
    }
}
