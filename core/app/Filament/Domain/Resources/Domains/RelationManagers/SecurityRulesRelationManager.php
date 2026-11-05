<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\SecurityRule;
use App\Support\FilamentHelp;
use App\Support\GeoVocabulary;
use App\Support\SecurityConfig;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use Illuminate\Validation\ValidationException;

class SecurityRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'securityRules';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord->securityRules()->count());
    }

    protected static ?string $title = 'Security allow/block rules';

    private const TYPES = ['ip' => 'IP address', 'cidr' => 'CIDR network', 'country' => 'Country', 'continent' => 'Continent'];

    private const ACTIONS = ['allow' => 'Allow', 'block' => 'Block'];

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->ruleFields());
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('priority')->label(FilamentHelp::label('Priority', 'Enabled rules are evaluated by ascending priority and then stable ID. First match wins; the default is allow.'))->sortable(), TextColumn::make('match_type')->label('Type')->badge()->color('gray')->formatStateUsing(fn (string $state): string => self::TYPES[$state] ?? $state),
            TextColumn::make('value')->searchable(), TextColumn::make('action')->badge()
                ->formatStateUsing(fn (string $state): string => self::ACTIONS[$state] ?? $state)
                ->color(fn (string $state): string => $state === 'block' ? 'danger' : 'success'),
            TextColumn::make('enabled')->label('State')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'), TextColumn::make('note')->limit(60)->placeholder('None'),
        ])->headerActions([
            CreateAction::make()->createAnother(false)->using(fn (array $data): SecurityRule => $this->createRule($data)),
            Action::make('importPreview')->label('Import rules')->icon('phosphor-upload-simple')->requiresConfirmation()
                ->modalHeading('Preview and commit security-rule import')
                ->modalDescription('Review every normalized row below. Confirming writes the whole bounded import in one desired revision.')
                ->schema([
                    Toggle::make('replace_existing')->label('Replace existing rules')->default(false),
                    Repeater::make('rules')->minItems(1)->maxItems(100)->schema($this->ruleFields())->columns(3)->required(),
                ])->action(fn (array $data) => $this->importRules($data)),
        ])->recordActions([
            EditAction::make()->using(fn (SecurityRule $record, array $data): SecurityRule => $this->updateRule($record, $data)),
            DeleteAction::make()->using(fn (SecurityRule $record): bool => $this->deleteRule($record)),
        ])->defaultSort('priority');
    }

    private function ruleFields(): array
    {
        $isText = fn (Get $get): bool => in_array($get('match_type'), ['ip', 'cidr', null, ''], true);

        return [
            Select::make('match_type')->label('Type')->options(self::TYPES)->required()->live()->placeholder('Choose what to match'),
            TextInput::make('value')->label('Value')->required()->maxLength(128)->visible($isText)
                ->placeholder(fn (Get $get): string => $get('match_type') === 'cidr' ? '203.0.113.0/24 or 2001:db8::/32' : '203.0.113.10 or 2001:db8::10')
                ->helperText(fn (Get $get): string => match ($get('match_type')) {
                    'cidr' => 'A network in CIDR notation: the address, a slash, and the prefix length.',
                    'ip' => 'One IPv4 or IPv6 address.',
                    default => 'Choose a type first.',
                }),
            Select::make('value')->label('Country')->options(GeoVocabulary::countryOptions())->searchable()->required()
                ->placeholder('Search by name or code')->visible(fn (Get $get): bool => $get('match_type') === 'country')
                ->helperText('Matched with the visitor address\'s country.'),
            Select::make('value')->label('Continent')->options(GeoVocabulary::continentOptions())->required()
                ->placeholder('Choose a continent')->visible(fn (Get $get): bool => $get('match_type') === 'continent'),
            Select::make('action')->options(self::ACTIONS)->required()->placeholder('Choose allow or block')
                ->helperText('Block returns 403 to the visitor. Allow stops checking later rules.'),
            TextInput::make('priority')->numeric()->default(100)->minValue(-1000000)->maxValue(1000000)->required()
                ->helperText('Lower numbers are checked first. The first rule that matches wins.'),
            Toggle::make('enabled')->default(true),
            TextInput::make('note')->maxLength(250)->placeholder('Why this rule exists (optional)'),
        ];
    }

    private function createRule(array $input): SecurityRule
    {
        $domain = $this->getOwnerRecord();
        abort_if($domain->securityRules()->count() >= config('security.maximum_rules_per_domain'), 409, 'The per-domain security-rule limit has been reached.');
        $data = SecurityConfig::validateRule($input);
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
        $data = SecurityConfig::validateRule($input);
        $domain = $this->getOwnerRecord();
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
        $rules = collect($input['rules'])->map(fn (array $rule): array => SecurityConfig::validateRule($rule));
        $duplicates = $rules->map(fn (array $rule): string => implode('|', [$rule['match_type'], $rule['value'], $rule['action']]))->duplicates();
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
            $locked->securityRules()->insert($rules->map(fn (array $rule): array => [...$rule, 'domain_id' => $locked->id, 'created_at' => $now, 'updated_at' => $now])->all());
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
}
