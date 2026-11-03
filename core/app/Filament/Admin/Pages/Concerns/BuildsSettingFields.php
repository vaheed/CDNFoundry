<?php

namespace App\Filament\Admin\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;

/** One typed control per platform setting, with its default shown and a reset link. Used by the settings page and the first-run wizard. */
trait BuildsSettingFields
{
    protected function field(string $name, array $field): TextInput|Toggle|TagsInput|CheckboxList|Select
    {
        $default = is_array($field['default']) ? json_encode($field['default'], JSON_UNESCAPED_SLASHES) : var_export($field['default'], true);
        $control = match ($field['type']) {
            'timezone' => Select::make($name)
                ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                ->searchable()->native(false)->required()->rules($field['rules']),
            'boolean' => Toggle::make($name),
            'cidr_list', 'ip_list' => TagsInput::make($name),
            'choice_list' => CheckboxList::make($name)->options($field['options'])->columns(2)
                ->dehydrateStateUsing(fn (array $state): array => array_map(fn (mixed $value): mixed => is_int($value) ? (string) $value : $value, $state)),
            'url' => TextInput::make($name)->url()->maxLength(2048)->placeholder('https://grafana.example.com/explore'),
            default => TextInput::make($name)->integer()->required()->extraInputAttributes(['class' => 'cdn-mono']),
        };

        return $control->label($field['label'])->inlineLabel()
            ->helperText($field['description'].' Default: '.$default.'.')
            ->hintAction(fn (): Action => Action::make('reset-'.str_replace('.', '-', $name))->label('Reset')->link()
                ->extraAttributes(['data-testid' => 'setting-reset-'.str_replace('.', '-', $name)])
                ->action(fn (Set $set) => $set($name, $field['default'])))
            ->extraAttributes(['data-testid' => 'setting-'.str_replace('.', '-', $name)]);
    }
}
