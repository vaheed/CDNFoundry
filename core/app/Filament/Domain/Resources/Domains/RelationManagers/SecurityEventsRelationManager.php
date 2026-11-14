<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Exceptions\AnalyticsUnavailableException;
use App\Filament\Shared\SecurityRuleForm;
use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\DomainSecurityRules;
use App\Support\FilamentHelp;
use App\Support\MitigationRules;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Requests the domain's rules and the managed WAF mitigated, from stored request events. The list is the newest 500 of the last
 * 24 hours, kept for 7 days, so it is a view of the stored events and not a count of everything that was mitigated.
 */
class SecurityEventsRelationManager extends RelationManager
{
    // The table reads ClickHouse through records(); the relationship only gives the manager its domain.
    protected static string $relationship = 'securityRules';

    protected static ?string $title = 'Security events';

    private ?array $loaded = null;

    private bool $unavailable = false;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return null;
    }

    public function table(Table $table): Table
    {
        return $table->recordAction(null)->recordUrl(null)->records(fn (array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => $this->page($filters, (int) $page, (int) $recordsPerPage))
            ->columns([
                TextColumn::make('occurred_at')->label('Time')->state(fn (array $record): string => CarbonImmutable::parse($record['occurred_at'], 'UTC')->diffForHumans())
                    ->tooltip(fn (array $record): string => CarbonImmutable::parse($record['occurred_at'], 'UTC')->format('Y-m-d H:i:s').' UTC'),
                TextColumn::make('rule_label')->label('Rule')->state(fn (array $record): string => $record['rule']['label'] ?? 'Platform protection')->limit(48)
                    ->tooltip(fn (array $record): ?string => strlen($record['rule']['label'] ?? '') > 48 ? $record['rule']['label'] : null)
                    ->description(fn (array $record): string => self::reasonLabel((string) $record['security_reason'])),
                TextColumn::make('security_action')->label('Action')->badge()
                    ->state(fn (array $record): string => $record['security_action'] === 'block' ? 'Blocked' : 'Logged')
                    ->color(fn (string $state): string => $state === 'Blocked' ? 'danger' : 'gray'),
                TextColumn::make('attack_score')->label(FilamentHelp::label('Score', 'The attack score from 1 (attack) to 99 (clean). A dash means the request was not scored.'))
                    ->state(fn (array $record): string => (int) $record['attack_score'] === 0 ? '-' : (string) $record['attack_score'])->alignEnd(),
                TextColumn::make('path')->limit(48)->tooltip(fn (array $record): ?string => strlen($record['path']) > 48 ? $record['path'] : null),
                TextColumn::make('country')->state(fn (array $record): string => in_array($record['country'], ['', 'unknown', 'ZZ'], true) ? '-' : $record['country']),
                TextColumn::make('asn')->label('ASN')->state(fn (array $record): string => (int) $record['asn'] === 0 ? '-' : (string) $record['asn'])->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_agent')->label('User agent')->limit(36)->tooltip(fn (array $record): ?string => strlen($record['user_agent']) > 36 ? $record['user_agent'] : null)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')->label('Request ID')->limit(12)->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('events')->schema([
                    Select::make('action')->label('Action')->options(['blocked' => 'Blocked', 'logged' => 'Logged'])->placeholder('Any action'),
                    TextInput::make('rule')->label(FilamentHelp::label('Rule reference', 'r12 is a rule of this domain, p5 a platform rule, and waf the managed firewall.'))->placeholder('r12'),
                    TextInput::make('score_max')->label(FilamentHelp::label('Attack score up to', 'Shows requests that scored this number or lower. 1 is a certain attack, 99 is clean.'))->numeric()->minValue(1)->maxValue(99)->placeholder('20'),
                    TextInput::make('path')->label('Path contains')->placeholder('/admin'),
                    TextInput::make('country')->label('Country')->maxLength(2)->placeholder('DE'),
                    TextInput::make('asn')->label('ASN')->numeric()->placeholder('64500'),
                    TextInput::make('user_agent')->label('User agent contains')->placeholder('sqlmap'),
                    TextInput::make('request_id')->label('Request ID')->placeholder('4fa1c2d9e0b34a7d'),
                ])->columns(2),
            ])->filtersFormWidth('lg')->deferFilters(false)
            ->recordActions([
                Action::make('createRule')->label('Create rule')->icon('phosphor-plus-circle')->modalHeading('Create a rule from this request')
                    ->modalDescription('The conditions come from the request. Remove the ones that should not matter.')
                    ->slideOver()->modalWidth(Width::TwoExtraLarge)->modalSubmitActionLabel('Create rule')
                    ->schema(SecurityRuleForm::fields($this->domain()))->fillForm(fn (array $record): array => $this->ruleFromEvent($record, false))
                    ->action(fn (array $data) => $this->save($data, 'Rule created')),
                Action::make('createException')->label('Create exception')->icon('phosphor-shield-slash')->modalHeading('Let requests like this one through')
                    ->modalDescription('An exception is a rule that runs before the rule that fired. It lets matching requests continue.')
                    ->visible(fn (array $record): bool => in_array($record['rule']['type'] ?? 'managed', ['domain', 'managed'], true))
                    ->slideOver()->modalWidth(Width::TwoExtraLarge)->modalSubmitActionLabel('Create exception')
                    ->schema(SecurityRuleForm::fields($this->domain()))->fillForm(fn (array $record): array => $this->ruleFromEvent($record, true))
                    ->action(fn (array $data) => $this->save($data, 'Exception created')),
            ])
            ->emptyStateHeading(fn (): string => $this->unavailable ? 'Security events are unavailable' : 'No mitigated requests')
            ->emptyStateDescription(fn (): string => $this->unavailable
                ? 'The telemetry store did not answer. Serving is not affected. Try again in a minute.'
                : 'Requests that a rule or the managed firewall blocks or logs appear here for 7 days.')
            ->defaultPaginationPageOption(25)->paginated([25, 50]);
    }

    private static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'waf_block' => 'Over the anomaly threshold',
            'custom_rule_block', 'platform_rule_block' => 'Rule matched',
            'custom_rule_rate_limited', 'platform_rule_rate_limited' => 'Rate limit exceeded',
            'custom_rule_challenge', 'platform_rule_challenge', 'challenge_required' => 'Challenge required',
            'custom_rule_log', 'platform_rule_log' => 'Rule logged the request',
            '' => 'Logged',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }

    /** @return array<int, array> */
    private function rows(array $filters): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $state = array_filter($filters['events'] ?? [], fn (mixed $value): bool => filled($value));
        try {
            $store = app(AnalyticsStore::class);
            $now = CarbonImmutable::now('UTC');
            $rows = $store->mitigations($this->getOwnerRecord(), ['from' => $now->subDay(), 'to' => $now, 'raw' => true], $state);
            $rules = MitigationRules::describe($this->getOwnerRecord(), array_column($rows, 'security_rule'));
            $this->loaded = array_map(fn (array $row): array => [...$row, 'rule' => $row['security_rule'] === '' ? null : ['ref' => $row['security_rule'], ...$rules[$row['security_rule']]]], $rows);
        } catch (AnalyticsUnavailableException) {
            $this->unavailable = true;
            $this->loaded = [];
        }

        return $this->loaded;
    }

    private function page(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->rows($filters);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(collect($slice)->mapWithKeys(fn (array $row): array => [$row['event_id'] => $row]), count($rows), $perPage, $page, ['pageName' => $this->getTablePaginationPageName()]);
    }

    /** The rule sheet's values for a request: its path, country, network, and user agent as conditions, and a safe default action. */
    private function ruleFromEvent(array $record, bool $exception): array
    {
        $conditions = [['field' => 'path', 'operator' => 'equals', 'value' => (string) $record['path']]];
        if (! in_array($record['country'], ['', 'unknown', 'ZZ'], true)) {
            $conditions[] = ['field' => 'country', 'operator' => 'equals', 'value' => (string) $record['country']];
        }
        if ((int) $record['asn'] > 0) {
            $conditions[] = ['field' => 'asn', 'operator' => 'equals', 'value' => (int) $record['asn']];
        }
        if (filled($record['user_agent'])) {
            $conditions[] = ['field' => 'user_agent', 'operator' => 'equals', 'value' => mb_substr((string) $record['user_agent'], 0, 512)];
        }
        if (! $exception) {
            return ['name' => 'From request '.mb_substr((string) $record['request_id'], 0, 12), 'conditions' => array_slice($conditions, 0, 5), 'action' => 'block', 'priority' => 100, 'enabled' => true];
        }
        $managed = ($record['rule']['type'] ?? 'managed') === 'managed';

        return [
            'name' => 'Exception for '.mb_substr((string) $record['path'], 0, 60), 'conditions' => [$conditions[0]],
            'action' => $managed ? 'skip_managed' : 'allow', 'enabled' => true,
            // A domain rule is passed by running just before it; the managed firewall runs after every rule, so any order works.
            'priority' => $managed ? 1 : (int) ($record['rule']['priority'] ?? 100) - 1,
        ];
    }

    private function save(array $data, string $title): void
    {
        $domain = $this->getOwnerRecord();
        DomainSecurityRules::create($domain, $data, auth()->user(), request()->ip());
        Notification::make()->success()->title($title)->body('The change is queued in the next signed edge revision.')->send();
    }

    private function domain(): Domain
    {
        $domain = $this->getOwnerRecord();
        assert($domain instanceof Domain);

        return $domain;
    }
}
