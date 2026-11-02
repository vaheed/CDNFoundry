<?php

namespace App\Filament\Admin\Resources\Users;

use App\Enums\UserType;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'email';

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            Select::make('type')->options([
                UserType::Admin->value => 'Administrator',
                UserType::User->value => 'Domain user',
            ])->required(),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->rule(Password::defaults()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn ($state): string => ucfirst(str_replace('_', ' ', $state instanceof \BackedEnum ? $state->value : (string) $state))),
                TextColumn::make('disabled_at')->label('State')->formatStateUsing(fn ($state): string => $state ? 'Disabled' : 'Active')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('id');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
