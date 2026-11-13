<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Filament\Domain\Resources\Domains\RelationManagers\Concerns\SyncsDomainPage;
use App\Filament\Shared\SecurityRuleForm;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\SecurityRule;
use App\Support\DomainSecurityRules;
use App\Support\FilamentHelp;
use App\Support\PlatformRulesets;
use App\Support\SecurityPresets;
use App\Support\SecurityRuleData;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SecurityRulesRelationManager extends RelationManager
{
    use SyncsDomainPage;

    protected static string $relationship = 'securityRules';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord->securityRules()->count());
    }

    protected static ?string $title = 'Custom rules';

    public function form(Schema $schema): Schema
    {
        return $schema->components(SecurityRuleForm::fields());
    }

    public function table(Table $table): Table
    {
        return $table->description(function (): ?string {
            $names = collect(PlatformRulesets::namesFor($this->getOwnerRecord()))->pluck('name');

            return $names->isEmpty() ? null : 'Applied first by your provider: '.$names->implode(', ').'. '.number_format(PlatformRulesets::matchesLast24Hours($this->getOwnerRecord())).' requests matched in the last 24 hours.';
        })->columns([
            TextColumn::make('priority')->label(FilamentHelp::label('Order', 'Rules run from the lowest number up. Allow stops the check; Block ends the request; Log, Challenge and Skip managed rules let later rules run.'))->sortable(),
            TextColumn::make('name')->label('Rule')
                ->state(fn (SecurityRule $record): string => $record->name ?? SecurityRuleData::describe($record->conditions))
                ->description(fn (SecurityRule $record): ?string => collect([$record->name === null ? null : SecurityRuleData::describe($record->conditions), $record->rate_limit === null ? null : 'Limit: '.SecurityRuleData::describeRateLimit($record->rate_limit, $record->action).'.'])->filter()->implode(' ') ?: null)->wrap(),
            TextColumn::make('action')->badge()
                ->formatStateUsing(fn (string $state): string => SecurityRuleData::ACTION_LABELS[$state] ?? $state)
                ->color(fn (string $state): string => match ($state) {
                    'block' => 'danger', 'challenge' => 'warning', 'allow' => 'success', default => 'gray',
                }),
            TextColumn::make('enabled')->label('State')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->headerActions([
            CreateAction::make()->label('Add rule')->createAnother(false)->using(fn (array $data): SecurityRule => $this->createRule($data)),
            ActionGroup::make([
                Action::make('userAgentPreset')->label('Block user agents')->icon('phosphor-robot')->modalHeading('Block or challenge user agents')
                    ->modalDescription('Each word becomes one custom rule. They are saved together, and saving again replaces them.')
                    ->schema([
                        TagsInput::make('values')->label(FilamentHelp::label('User agents', 'A request is handled when its user agent contains one of these words. Case does not matter. Up to '.SecurityPresets::MAXIMUM_USER_AGENTS.'.'))
                            ->required()->placeholder('sqlmap')->helperText('Type a word and press Enter.'),
                        Select::make('action')->label(FilamentHelp::label('Action', 'Block answers 403. Challenge asks a browser for a short proof of work and refuses scripts.'))
                            ->options(['block' => 'Block', 'challenge' => 'Challenge'])->default('block')->required()->placeholder('Choose what happens'),
                    ])->fillForm(fn (): array => SecurityPresets::current($this->getOwnerRecord(), SecurityPresets::USER_AGENT) ?? ['action' => 'block'])
                    ->action(fn (array $data) => $this->savePreset(SecurityPresets::USER_AGENT, SecurityPresets::userAgentRules($data), 'User agent rules saved')),
                Action::make('removeUserAgentPreset')->label('Remove user agent rules')->icon('phosphor-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => $this->getOwnerRecord()->securityRules()->where('preset', SecurityPresets::USER_AGENT)->exists())
                    ->action(fn () => $this->savePreset(SecurityPresets::USER_AGENT, null, 'User agent rules removed')),
                Action::make('hotlinkPreset')->label('Hotlink protection')->icon('phosphor-image')->modalHeading('Protect files from hotlinking')
                    ->modalDescription('Pages on other sites cannot embed these files. One custom rule blocks them with a 403.')
                    ->schema([
                        TagsInput::make('extensions')->label(FilamentHelp::label('File extensions', 'Requests for files that end with these extensions are protected. Type the extension without a dot.'))
                            ->required()->placeholder('jpg')->helperText('Type an extension and press Enter.'),
                        TagsInput::make('allowed_hosts')->label(FilamentHelp::label('Allowed referer hosts', 'Sites whose pages may embed the files. Your own domain and its www form are always allowed, and so is a request with no Referer.'))
                            ->placeholder('www.partner.example')->helperText('Type a host name and press Enter.'),
                    ])->fillForm(fn (): array => SecurityPresets::current($this->getOwnerRecord(), SecurityPresets::HOTLINK) ?? ['extensions' => ['jpg', 'png', 'gif', 'webp']])
                    ->action(fn (array $data) => $this->savePreset(SecurityPresets::HOTLINK, SecurityPresets::hotlinkRules($this->getOwnerRecord(), $data), 'Hotlink protection saved')),
                Action::make('removeHotlinkPreset')->label('Remove hotlink protection')->icon('phosphor-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => $this->getOwnerRecord()->securityRules()->where('preset', SecurityPresets::HOTLINK)->exists())
                    ->action(fn () => $this->savePreset(SecurityPresets::HOTLINK, null, 'Hotlink protection removed')),
            ])->label('Presets')->icon('phosphor-sliders-horizontal')->button(),
            Action::make('importPreview')->label('Import rules')->icon('phosphor-upload-simple')->requiresConfirmation()
                ->modalHeading('Import custom rules')
                ->modalDescription('Confirming writes every row below in one desired revision.')
                ->schema([
                    Toggle::make('replace_existing')->label('Replace existing rules')->default(false),
                    Repeater::make('rules')->minItems(1)->maxItems(100)->schema(SecurityRuleForm::fields(false))->required(),
                ])->action(fn (array $data) => $this->importRules($data)),
        ])->recordActions([
            Action::make('moveUp')->label('Move up')->icon('phosphor-arrow-up')->action(fn (SecurityRule $record) => $this->move($record, -1)),
            Action::make('moveDown')->label('Move down')->icon('phosphor-arrow-down')->action(fn (SecurityRule $record) => $this->move($record, 1)),
            EditAction::make()->using(fn (SecurityRule $record, array $data): SecurityRule => $this->updateRule($record, $data)),
            DeleteAction::make()->using(fn (SecurityRule $record): bool => $this->deleteRule($record)),
        ])->defaultSort('priority')->emptyStateHeading('No custom rules')
            ->emptyStateDescription('Add a rule to block, challenge, log, or allow requests that match up to five conditions.');
    }

    private function createRule(array $input): SecurityRule
    {
        return DomainSecurityRules::create($this->getOwnerRecord(), $input, auth()->user(), request()->ip());
    }

    private function updateRule(SecurityRule $rule, array $input): SecurityRule
    {
        $data = SecurityRuleData::validate($input);
        $domain = $this->getOwnerRecord();
        $this->refuseDuplicate($domain, $data, $rule);
        SecurityRuleData::assertRateLimitCapacity($domain, $data['rate_limit'] === null ? 0 : 1, false, $rule->id);
        DB::transaction(function () use ($data, $domain, $rule): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $rule->update([...$data, 'preset' => null]);
            $this->changed($locked, 'security.rule_updated', $rule);
        });
        $this->reconcile($domain);

        return $rule->refresh();
    }

    private function deleteRule(SecurityRule $rule): bool
    {
        $domain = $this->getOwnerRecord();
        DB::transaction(function () use ($domain, $rule): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $rule->delete();
            $this->changed($locked, 'security.rule_deleted', $locked);
        });
        $this->reconcile($domain);

        return true;
    }

    private function importRules(array $input): void
    {
        $domain = $this->getOwnerRecord();
        $rules = collect($input['rules'])->map(fn (array $rule): array => SecurityRuleData::validate($rule));
        $duplicates = $rules->pluck('fingerprint')->duplicates();
        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages(['rules' => 'The import contains duplicate normalized rules.']);
        }
        $replace = (bool) ($input['replace_existing'] ?? false);
        abort_if(! $replace && $domain->securityRules()->count() + $rules->count() > config('security.maximum_rules_per_domain'), 409, 'The import exceeds the per-domain rule limit.');
        SecurityRuleData::assertRateLimitCapacity($domain, $rules->whereNotNull('rate_limit')->count(), $replace);
        DB::transaction(function () use ($domain, $replace, $rules): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($replace) {
                $locked->securityRules()->delete();
            }
            $now = now();
            $locked->securityRules()->insert($rules->map(fn (array $rule): array => [...SecurityRuleData::row($rule), 'domain_id' => $locked->id, 'created_at' => $now, 'updated_at' => $now])->all());
            $locked->update(['revision' => $locked->revision + 1]);
            AuditLog::record(auth()->user(), 'security.rules_imported', $locked, ['count' => $rules->count(), 'replace_existing' => $replace, 'revision' => $locked->revision], request()->ip());
        });
        $this->reconcile($domain);
        Notification::make()->success()->title('Security rules imported')->body("{$rules->count()} rules were saved in one desired revision.")->send();
    }

    private function changed(Domain $domain, string $action, object $subject): void
    {
        $domain->update(['revision' => $domain->revision + 1]);
        AuditLog::record(auth()->user(), $action, $subject, ['domain_id' => $domain->id, 'revision' => $domain->revision], request()->ip());
    }

    private function reconcile(Domain $domain): void
    {
        Operation::coalesceDomain('edge.domain_reconcile', $domain->id, auth()->id());
        ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
    }

    private function savePreset(string $preset, ?array $rules, string $title): void
    {
        $domain = $this->getOwnerRecord();
        SecurityPresets::replace($domain, $preset, $rules, auth()->user(), request()->ip());
        $this->reconcile($domain);
        Notification::make()->success()->title($title)->body('The change is queued in the next signed edge revision.')->send();
    }

    private function refuseDuplicate(Domain $domain, array $data, ?SecurityRule $except = null): void
    {
        $duplicate = $domain->securityRules()->where('fingerprint', $data['fingerprint'])->when($except, fn ($query) => $query->whereKeyNot($except->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['data.conditions' => 'The domain already has a rule with the same conditions and action.']);
        }
    }

    private function move(SecurityRule $rule, int $direction): void
    {
        $domain = $this->getOwnerRecord();
        DB::transaction(function () use ($domain, $rule, $direction): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $ids = $locked->securityRules()->orderBy('priority')->orderBy('id')->pluck('id')->all();
            $index = array_search($rule->id, $ids, true);
            $target = $index === false ? false : $index + $direction;
            if ($target === false || ! isset($ids[$target])) {
                return;
            }
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            foreach ($ids as $position => $id) {
                $locked->securityRules()->whereKey($id)->update(['priority' => ($position + 1) * 10]);
            }
            $this->changed($locked, 'security.rules_reordered', $locked);
        });
        $this->reconcile($domain);
    }
}
