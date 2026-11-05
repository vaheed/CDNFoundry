<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\BuildsSettingFields;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\User;
use App\Support\PlatformSettings as SettingsRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;

/** Walks an administrator through the CDN settings once. Every value shown is the current one next to its default; nothing is required. */
class FirstRunSetup extends Page
{
    use BuildsSettingFields;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-compass';

    protected static ?string $navigationLabel = 'First-run setup';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'First-run setup';

    protected static ?string $slug = 'first-run-setup';

    protected string $view = 'filament.admin.pages.first-run-setup';

    public ?array $data = [];

    public static function completed(): bool
    {
        return AuditLog::query()->where('action', 'setup.completed')->exists();
    }

    /** A fresh install: the administrator is the only user and nothing has been created or changed yet. */
    public static function systemIsUntouched(): bool
    {
        return User::query()->count() <= 1
            && ! Domain::query()->exists()
            && ! Edge::query()->exists()
            && ! AuditLog::query()->where('action', '!=', 'auth.login')->exists();
    }

    /** Offered only on a fresh install; any change to the system, finishing, or skipping retires it for good. */
    public static function available(): bool
    {
        return ! self::completed() && self::systemIsUntouched();
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->isAdmin() ?? false) && self::available();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::available();
    }

    public function getSubheading(): ?string
    {
        return 'Every setting has a default, shown beside it. Keep the defaults and finish, or change what you need. You can change any of this later under Platform settings.';
    }

    public function mount(SettingsRegistry $settings): void
    {
        $this->form->fill(collect($settings->definitions())->mapWithKeys(fn (array $_, string $group): array => [$group => $settings->values($group)])->all());
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(SettingsRegistry::class);
        $steps = collect($settings->definitions())->map(function (array $definition, string $group): Step {
            $fields = collect($definition['fields'])->map(fn (array $field, string $key) => $this->field("{$group}.{$key}", $field))->all();

            $reset = Actions::make([
                Action::make('reset-step-'.$group)->label('Reset this step to defaults')->link()
                    ->action(function (Set $set) use ($definition, $group): void {
                        foreach ($definition['fields'] as $key => $field) {
                            $set("{$group}.{$key}", $field['default']);
                        }
                    }),
            ]);

            return Step::make($definition['label'])->description($definition['description'])->schema([...$fields, $reset])->columns(1)
                ->extraAttributes(['data-testid' => 'first-run-step-'.str_replace('_', '-', $group)]);
        })->values()->all();

        return $schema->statePath('data')->components([
            Wizard::make($steps)->skippable()->persistStepInQueryString()
                ->submitAction(Action::make('finish')->label('Save and finish')->submit('finish')->color('primary')),
        ]);
    }

    public function finish(SettingsRegistry $settings): void
    {
        $state = $this->form->getState();
        $queued = 0;
        foreach (array_keys($settings->definitions()) as $group) {
            $result = $settings->update($group, $state[$group] ?? [], auth()->user(), request()->ip());
            $queued += $result['operation'] !== null ? 1 : 0;
        }
        AuditLog::record(auth()->user(), 'setup.completed', null, ['mode' => 'saved'], request()->ip());
        Notification::make()->success()->title('Setup finished')->body($queued === 0 ? 'The validated settings are active.' : "Runtime reconciliation queued for {$queued} setting group(s).")->send();
        $this->redirect(PlatformSettings::getUrl());
    }

    public function skipSetup(): void
    {
        AuditLog::record(auth()->user(), 'setup.completed', null, ['mode' => 'skipped'], request()->ip());
        Notification::make()->success()->title('Defaults kept')->send();
        $this->redirect(PlatformSettings::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('skipSetup')->label('Keep all defaults')->color('gray')->requiresConfirmation()
            ->modalHeading('Keep every default?')->modalDescription('Nothing changes. You can still edit any setting under Platform settings.')->action(fn () => $this->skipSetup())];
    }
}
