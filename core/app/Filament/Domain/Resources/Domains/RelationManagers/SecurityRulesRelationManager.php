<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Filament\Domain\Resources\Domains\RelationManagers\Concerns\SyncsDomainPage;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\SecurityRule;
use App\Support\FilamentHelp;
use App\Support\GeoVocabulary;
use App\Support\SecurityRuleData;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
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

    private const EXAMPLES = [
        'hostname' => 'www.example.com', 'path' => '/admin', 'user_agent' => 'BadBot', 'referer' => 'https://evil.example',
        'header' => 'secret-value', 'content_type' => 'application/json', 'client_ip' => '203.0.113.0/24',
    ];

    private const HELP = [
        'hostname' => 'The hostname the visitor asked for.', 'path' => 'The request path without the query string. Case matters.',
        'query_present' => 'Whether the address has a query string at all.', 'method' => 'The HTTP method of the request.',
        'country' => 'The visitor\'s country, from the edge\'s location database.', 'continent' => 'The visitor\'s continent.',
        'asn' => 'The visitor\'s network number. Unknown networks never match "equals" or a comparison.',
        'client_ip' => 'One address or a network such as 203.0.113.0/24. IPv4 and IPv6 both work.',
        'user_agent' => 'The browser or client name the visitor sends. Case does not matter.',
        'referer' => 'The page the visitor came from. Case does not matter.', 'header' => 'The value of one request header. Case does not matter.',
        'cookie_present' => 'Whether the request carries a cookie with this name. The value is never read.',
        'content_type' => 'The Content-Type header of the request.', 'body_size' => 'The request body size in bytes.',
        'tls_version' => 'The TLS version of the connection. Plain HTTP reports "none".',
        'attack_score' => 'From 1 (attack) to 99 (clean), from the managed WAF\'s anomaly points. Below 20 is a very likely attack. Requests that could not be scored never match.',
        'attack_score_sqli' => 'The same 1 to 99 score counting only SQL injection signs.',
        'attack_score_xss' => 'The same 1 to 99 score counting only cross-site scripting signs.',
        'attack_score_rce' => 'The same 1 to 99 score counting only command injection signs.',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->ruleFields());
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('priority')->label(FilamentHelp::label('Order', 'Rules run from the lowest number up. Allow stops the check; Block ends the request; Log, Challenge and Skip managed rules let later rules run.'))->sortable(),
            TextColumn::make('name')->label('Rule')
                ->state(fn (SecurityRule $record): string => $record->name ?? SecurityRuleData::describe($record->conditions))
                ->description(fn (SecurityRule $record): ?string => $record->name === null ? null : SecurityRuleData::describe($record->conditions))->wrap(),
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
            Action::make('importPreview')->label('Import rules')->icon('phosphor-upload-simple')->requiresConfirmation()
                ->modalHeading('Import custom rules')
                ->modalDescription('Confirming writes every row below in one desired revision.')
                ->schema([
                    Toggle::make('replace_existing')->label('Replace existing rules')->default(false),
                    Repeater::make('rules')->minItems(1)->maxItems(100)->schema($this->ruleFields(false))->required(),
                ])->action(fn (array $data) => $this->importRules($data)),
        ])->recordActions([
            Action::make('moveUp')->label('Move up')->icon('phosphor-arrow-up')->action(fn (SecurityRule $record) => $this->move($record, -1)),
            Action::make('moveDown')->label('Move down')->icon('phosphor-arrow-down')->action(fn (SecurityRule $record) => $this->move($record, 1)),
            EditAction::make()->using(fn (SecurityRule $record, array $data): SecurityRule => $this->updateRule($record, $data)),
            DeleteAction::make()->using(fn (SecurityRule $record): bool => $this->deleteRule($record)),
        ])->defaultSort('priority')->emptyStateHeading('No custom rules')
            ->emptyStateDescription('Add a rule to block, challenge, log, or allow requests that match up to five conditions.');
    }

    private function ruleFields(bool $withPreview = true): array
    {
        $fields = [
            TextInput::make('name')->label('Name')->maxLength(100)->placeholder('Block the admin area from abroad'),
            Repeater::make('conditions')->label(FilamentHelp::label('Conditions', 'Every condition must match for the rule to apply. Up to five.'))
                ->minItems(1)->maxItems(SecurityRuleData::MAXIMUM_CONDITIONS)->defaultItems(1)->addActionLabel('Add condition')->live()
                ->schema($this->conditionFields())->columns(2)->required(),
            Select::make('action')->label(FilamentHelp::label('Action', 'Block answers 403. Challenge asks a browser for a short proof of work. Log records an event and continues. Allow stops all later rules and skips the challenge. Skip managed rules turns the managed WAF off for the request.'))
                ->options(SecurityRuleData::ACTION_LABELS)->required()->placeholder('Choose what happens'),
            TextInput::make('priority')->label(FilamentHelp::label('Order', 'Lower numbers run first. Use the move buttons in the list to change it later.'))->numeric()->default(100)
                ->minValue(-1000000)->maxValue(1000000)->required()->placeholder('100'),
            Toggle::make('enabled')->label('Enabled')->default(true),
            TextInput::make('note')->label('Note')->maxLength(250)->placeholder('Why this rule exists'),
        ];
        if ($withPreview) {
            $fields[] = Placeholder::make('reads_as')->label('Reads as')->content(function (Get $get): HtmlString {
                $conditions = collect($get('conditions') ?? [])->filter(fn ($row): bool => is_array($row) && filled($row['field'] ?? null) && filled($row['operator'] ?? null)
                    && (is_bool($row['value'] ?? null) || filled($row['value'] ?? null)))->all();
                $action = SecurityRuleData::ACTION_LABELS[$get('action')] ?? null;

                return new HtmlString(e($conditions === [] ? 'Fill in a condition to preview the rule.' : (SecurityRuleData::describe($conditions).($action ? ' → '.$action : ''))));
            });
        }

        return $fields;
    }

    private function conditionFields(): array
    {
        $kind = fn (Get $get): ?string => SecurityRuleData::FIELDS[$get('field')]['kind'] ?? null;
        $list = fn (Get $get): bool => $get('operator') === 'in_list';
        $text = fn (Get $get): bool => in_array($kind($get), ['text', 'address'], true) && ! $list($get);
        $choice = fn (string $name) => fn (Get $get): bool => $kind($get) === $name;

        return [
            Select::make('field')->label('Field')->options(SecurityRuleData::FIELD_LABELS)->required()->live()->placeholder('Choose a field')
                ->afterStateUpdated(fn (callable $set) => $set('operator', 'equals'))
                ->helperText(fn (Get $get): ?string => self::HELP[$get('field')] ?? null),
            Select::make('operator')->label('Operator')->required()->live()->placeholder('Choose how to compare')
                ->options(fn (Get $get): array => collect(SecurityRuleData::FIELDS[$get('field')]['operators'] ?? [])->mapWithKeys(fn (string $operator): array => [$operator => SecurityRuleData::OPERATOR_LABELS[$operator]])->all()),
            TextInput::make('name')->label('Name')->maxLength(64)->required()->placeholder(fn (Get $get): string => $get('field') === 'cookie_present' ? 'session' : 'X-Api-Key')
                ->visible(fn (Get $get): bool => (SecurityRuleData::FIELDS[$get('field')]['named'] ?? false) === true)->columnSpanFull(),
            TextInput::make('value')->label('Value')->required()->maxLength(512)->visible($text)->columnSpanFull()
                ->placeholder(fn (Get $get): string => self::EXAMPLES[$get('field')] ?? 'Value'),
            TextInput::make('value')->label('Value')->numeric()->required()->visible(fn (Get $get): bool => in_array($kind($get), ['asn', 'bytes', 'score'], true) && ! $list($get))
                ->placeholder(fn (Get $get): string => match ($kind($get)) {
                    'asn' => '64500', 'score' => '20', default => '1048576'
                })->columnSpanFull(),
            TagsInput::make('value')->label('Values')->required()->visible(fn (Get $get): bool => $list($get) && in_array($kind($get), ['text', 'address', 'asn', 'score'], true))
                ->placeholder(fn (Get $get): string => match ($kind($get)) {
                    'asn' => '64500', 'score' => '10', default => self::EXAMPLES[$get('field')] ?? 'Value'
                })
                ->helperText('Type a value and press Enter. Up to '.SecurityRuleData::listValuesLimit().' values.')->columnSpanFull(),
            Select::make('value')->label('Country')->options(GeoVocabulary::countryOptions())->searchable()->multiple(fn (Get $get): bool => $list($get))->required()
                ->visible($choice('country'))->placeholder('Search by name or code')->columnSpanFull(),
            Select::make('value')->label('Continent')->options(GeoVocabulary::continentOptions())->multiple(fn (Get $get): bool => $list($get))->required()
                ->visible($choice('continent'))->placeholder('Choose a continent')->columnSpanFull(),
            Select::make('value')->label('Method')->options(array_combine(SecurityRuleData::HTTP_METHODS, SecurityRuleData::HTTP_METHODS))->multiple(fn (Get $get): bool => $list($get))->required()
                ->visible($choice('method'))->placeholder('Choose a method')->columnSpanFull(),
            Select::make('value')->label('TLS version')->options(array_combine(SecurityRuleData::TLS_VERSIONS, SecurityRuleData::TLS_VERSIONS))->multiple(fn (Get $get): bool => $list($get))->required()
                ->visible($choice('tls'))->placeholder('Choose a version')->columnSpanFull(),
            Toggle::make('value')->label(FilamentHelp::label('Present', 'On matches requests that have it. Off matches requests that do not.'))->default(true)->visible($choice('flag'))->columnSpanFull(),
        ];
    }

    private function createRule(array $input): SecurityRule
    {
        $domain = $this->getOwnerRecord();
        abort_if($domain->securityRules()->count() >= config('security.maximum_rules_per_domain'), 409, 'The per-domain security-rule limit has been reached.');
        $data = SecurityRuleData::validate($input);
        $this->refuseDuplicate($domain, $data);
        $rule = DB::transaction(function () use ($data, $domain): SecurityRule {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $rule = $locked->securityRules()->create($data);
            $this->changed($locked, 'security.rule_created', $rule);

            return $rule;
        });
        $this->reconcile($domain);

        return $rule;
    }

    private function updateRule(SecurityRule $rule, array $input): SecurityRule
    {
        $data = SecurityRuleData::validate($input);
        $domain = $this->getOwnerRecord();
        $this->refuseDuplicate($domain, $data, $rule);
        DB::transaction(function () use ($data, $domain, $rule): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $rule->update($data);
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
        DB::transaction(function () use ($domain, $replace, $rules): void {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($replace) {
                $locked->securityRules()->delete();
            }
            $now = now();
            $locked->securityRules()->insert($rules->map(fn (array $rule): array => [...$rule, 'conditions' => json_encode($rule['conditions'], JSON_THROW_ON_ERROR), 'domain_id' => $locked->id, 'created_at' => $now, 'updated_at' => $now])->all());
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
