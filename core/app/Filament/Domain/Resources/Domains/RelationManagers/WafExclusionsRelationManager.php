<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Http\Controllers\WafController;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WafExclusionsRelationManager extends RelationManager
{
    protected static string $relationship = 'wafExclusions';

    protected static ?string $title = 'Managed WAF exclusions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('dimension')->options(['path' => 'Literal path', 'rule' => 'CRS rule ID', 'parameter' => 'Parameter name', 'cookie' => 'Cookie name'])->required(),
            TextInput::make('value')->maxLength(255)->required(),
            TextInput::make('rule_id')->numeric()->minValue(900000)->maxValue(999999)->nullable(),
            Textarea::make('reason')->minLength(10)->maxLength(255)->required(),
            DateTimePicker::make('expires_at')->minDate(now())->maxDate(now()->addDays(30))->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('dimension')->badge(), TextColumn::make('value')->limit(50),
            TextColumn::make('rule_id')->placeholder('All applicable rules'),
            TextColumn::make('reason')->limit(60), TextColumn::make('owner.name')->label('Owner'),
            TextColumn::make('expires_at')->dateTime()->sortable(),
        ])->headerActions([
            CreateAction::make()->createAnother(false)->using(function (array $data) {
                $response = app(WafController::class)->storeExclusion(request()->merge($data), $this->getOwnerRecord());

                return $this->getOwnerRecord()->wafExclusions()->findOrFail($response->getData(true)['data']['exclusion']['id']);
            }),
        ])->recordActions([
            DeleteAction::make()->using(function ($record): bool {
                app(WafController::class)->destroyExclusion(request(), $this->getOwnerRecord(), $record);

                return true;
            }),
        ])->defaultSort('expires_at');
    }
}
