<?php

namespace App\Filament\Admin\Pages;

use App\Support\PlatformSettings as SettingsRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PlatformSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-sliders-horizontal';

    protected static ?string $navigationLabel = 'Platform settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Platform settings';

    protected string $view = 'filament.admin.pages.platform-settings';

    public ?array $data = [];

    public function getSubheading(): ?string
    {
        return 'Defaults apply to every domain; each setting shows its default.';
    }

    public function mount(SettingsRegistry $settings): void
    {
        $this->form->fill(collect($settings->definitions())->mapWithKeys(fn (array $_, string $group): array => [$group => $settings->values($group)])->all());
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(SettingsRegistry::class);
        $sections = collect($settings->definitions())->map(function (array $definition, string $group): Section {
            $fields = collect($definition['fields'])->map(fn (array $field, string $key) => $this->field("{$group}.{$key}", $field))->all();

            return Section::make($definition['label'])->description($definition['description'])->schema($fields)->columns(1)->compact()
                ->extraAttributes(['data-settings-section' => 'settings-'.str_replace('_', '-', $group)]);
        })->values()->all();

        return $schema->statePath('data')->components($sections);
    }

    /** @return list<array{id: string, label: string, count: int}> */
    public function getCategoriesProperty(): array
    {
        return collect(app(SettingsRegistry::class)->definitions())
            ->map(fn (array $definition, string $group): array => ['id' => 'settings-'.str_replace('_', '-', $group), 'label' => $definition['label'], 'count' => count($definition['fields'])])
            ->values()->all();
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

    private function field(string $name, array $field): TextInput|Toggle|TagsInput|CheckboxList|Select
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
