<?php

namespace App\Filament\Admin\Pages;

use App\Support\PlatformSettings as SettingsRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlatformSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Platform settings';

    protected string $view = 'filament.admin.pages.platform-settings';

    public ?array $data = [];

    public function mount(SettingsRegistry $settings): void
    {
        $this->form->fill(collect($settings->definitions())->mapWithKeys(fn (array $_, string $group): array => [$group => $settings->values($group)])->all());
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(SettingsRegistry::class);
        $sections = collect($settings->definitions())->map(function (array $definition, string $group): Section {
            $fields = collect($definition['fields'])->map(fn (array $field, string $key) => $this->field("{$group}.{$key}", $field))->all();

            return Section::make($definition['label'])->description($definition['description'])->schema($fields)->columns(2);
        })->values()->all();

        return $schema->statePath('data')->components($sections);
    }

    public function save(SettingsRegistry $settings): void
    {
        $state = $this->form->getState();
        $operations = [];
        foreach (array_keys($settings->definitions()) as $group) {
            $result = $settings->update($group, $state[$group] ?? [], auth()->user(), request()->ip());
            if ($result['operation'] !== null) {
                $operations[] = $result['operation']->getKey();
            }
        }
        Notification::make()->success()->title('Platform settings saved')->body($operations === []
            ? 'The validated PostgreSQL settings are active.'
            : 'Runtime reconciliation queued: '.implode(', ', $operations))->send();
        $this->mount($settings);
    }

    private function field(string $name, array $field): TextInput|Toggle|TagsInput|CheckboxList
    {
        $default = is_array($field['default']) ? json_encode($field['default'], JSON_UNESCAPED_SLASHES) : var_export($field['default'], true);
        $help = $field['description'].' Default: '.$default.'.';

        return match ($field['type']) {
            'boolean' => Toggle::make($name)->label($field['label'])->helperText($help),
            'cidr_list', 'ip_list' => TagsInput::make($name)->label($field['label'])->helperText($help),
            'choice_list' => CheckboxList::make($name)->label($field['label'])->options($field['options'])->helperText($help)->columns(2),
            default => TextInput::make($name)->label($field['label'])->integer()->required()->helperText($help),
        };
    }
}
