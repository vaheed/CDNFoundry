<?php

namespace App\Filament\Shared;

use App\Exceptions\AnalyticsUnavailableException;
use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\FilamentHelp;
use App\Support\GeoVocabulary;
use App\Support\SecurityRuleData;
use App\Support\SecurityRuleImpact;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** The fields of one typed security rule, shared by a domain's firewall rules and the rules of a platform ruleset. */
final class SecurityRuleForm
{
    private const EXAMPLES = [
        'hostname' => 'www.example.com', 'path' => '/admin', 'user_agent' => 'BadBot', 'referer' => 'https://evil.example',
        'header' => 'secret-value', 'content_type' => 'application/json', 'client_ip' => '203.0.113.0/24', 'referer_host' => 'www.example.com', 'file_extension' => 'jpg',
    ];

    private const HELP = [
        'hostname' => 'The hostname the visitor asked for.', 'path' => 'The request path without the query string. Case matters.',
        'query_present' => 'Whether the address has a query string at all.', 'method' => 'The HTTP method of the request.',
        'country' => 'The visitor\'s country, from the edge\'s location database.', 'continent' => 'The visitor\'s continent.',
        'asn' => 'The visitor\'s network number. Unknown networks never match "equals" or a comparison.',
        'client_ip' => 'One address or a network such as 203.0.113.0/24. IPv4 and IPv6 both work.',
        'user_agent' => 'The browser or client name the visitor sends. Case does not matter.',
        'referer_host' => 'Only the host of the Referer header, such as www.example.com. A request without a Referer has none.',
        'referer_present' => 'Whether the request carries a Referer header with an address.',
        'file_extension' => 'The extension of the requested path in lower case, without the dot: jpg for /a/photo.JPG.',
        'referer' => 'The page the visitor came from. Case does not matter.', 'header' => 'The value of one request header. Case does not matter.',
        'cookie_present' => 'Whether the request carries a cookie with this name. The value is never read.',
        'content_type' => 'The Content-Type header of the request.', 'body_size' => 'The request body size in bytes.',
        'tls_version' => 'The TLS version of the connection. Plain HTTP reports "none".',
        'attack_score' => 'From 1 (attack) to 99 (clean), from the managed WAF\'s anomaly points. Below 20 is a very likely attack. Requests that could not be scored never match.',
        'attack_score_sqli' => 'The same 1 to 99 score counting only SQL injection signs.',
        'attack_score_xss' => 'The same 1 to 99 score counting only cross-site scripting signs.',
        'attack_score_rce' => 'The same 1 to 99 score counting only command injection signs.',
        'prompt_injection_score' => '1 (two or more known injection phrases), 29 (one phrase), or 99 (none), from fixed phrase patterns on the prompt of your AI endpoints. Pattern based: it does not understand the prompt.',
        'prompt_pii' => 'Whether the prompt holds an email address, payment card number, IBAN, or US social security number.',
        'schema_violation' => 'How the request differs from the API schema: an unknown path, a method the path does not allow, a missing or wrongly typed parameter, or a JSON body over the limit.',
        'client_ip_list' => 'Whether the visitor\'s address is inside one of the networks of a list of this domain. Operators: is on, is not on.',
        'leaked_password' => 'Whether the password sent to one of the login paths is in the list of known leaked passwords.',
    ];

    /** @return array<string, string> */
    private static function violationOptions(): array
    {
        return ['any' => 'Any violation', 'none' => 'No violation', 'unknown_path' => 'Unknown path', 'wrong_method' => 'Wrong method', 'missing_parameter' => 'Missing parameter',
            'invalid_parameter' => 'Wrongly typed parameter', 'body_too_large' => 'JSON body too large'];
    }

    /**
     * One column in reading order: when (conditions), then (action and an optional rate limit), the rule read back in words,
     * and the optional details. With a domain, fields whose detection the domain has not set up stay visible but cannot be picked.
     */
    public static function fields(?Domain $domain = null): array
    {
        $unavailable = $domain === null ? [] : SecurityRuleData::unavailableFields($domain);

        return [
            Section::make('When')->description('Every condition must match. Up to five.')->compact()->schema([
                self::conditionRepeater($unavailable, null, $domain === null ? null : $domain->ipLists()->orderBy('name')->pluck('name', 'name')->all())
                    ->minItems(fn (Get $get): int => $get('rate_limited') ? 0 : 1)->required(fn (Get $get): bool => ! $get('rate_limited')),
            ]),
            Section::make('Then')->compact()->schema([
                Select::make('action')->label(FilamentHelp::label('Action', 'Block answers 403 (429 for a rate limit). Challenge asks a browser for a short proof of work. Log records an event and continues. Allow stops all later rules and skips the challenge. Skip managed rules turns the managed WAF off for the request.'))
                    ->options(fn (Get $get): array => $get('rate_limited') ? array_intersect_key(SecurityRuleData::ACTION_LABELS, array_flip(SecurityRuleData::RATE_ACTIONS)) : SecurityRuleData::ACTION_LABELS)
                    ->required()->live()->placeholder('Choose what happens'),
                Toggle::make('rate_limited')->label(FilamentHelp::label('Only above a request rate', 'Count the requests that match, and apply the action only once a client goes over the limit. Counted separately on each cell, not as one global count across locations.'))->live()
                    ->afterStateHydrated(fn (Toggle $component, mixed $record) => $component->state(filled(is_array($record) ? ($record['rate_limit'] ?? null) : $record?->rate_limit)))->dehydrated(false)->default(false),
                Group::make(self::rateLimitFields())->statePath('rate_limit')->columns(['default' => 1, 'sm' => 2])->visible(fn (Get $get): bool => (bool) $get('rate_limited')),
                Select::make('mode')->label(FilamentHelp::label('Mode', 'Enforce applies the action. Shadow evaluates the rule and records what it would have done, and never changes a response. Only block and challenge rules can run in shadow.'))
                    ->options(SecurityRuleData::MODES)->default('enforce')->native(false)
                    ->visible(fn (Get $get): bool => in_array($get('action'), SecurityRuleData::SHADOW_ACTIONS, true)),
            ]),
            ...self::estimate($domain),
            Text::make(function (Get $get): string {
                $conditions = collect(self::toStored($get('conditions')))->filter(fn ($row): bool => is_array($row) && filled($row['field'] ?? null) && filled($row['operator'] ?? null)
                    && (is_bool($row['value'] ?? null) || filled($row['value'] ?? null)))->values()->all();
                $action = SecurityRuleData::ACTION_LABELS[$get('action')] ?? null;
                if ($conditions === [] && ! $get('rate_limited')) {
                    return 'Reads as: fill in a condition to see the rule in words.';
                }

                return 'Reads as: '.SecurityRuleData::describe($conditions).($action ? ', then '.strtolower($action) : '').'.';
            })->extraAttributes(['data-testid' => 'rule-reads-as', 'class' => 'cdn-rule-reads-as']),
            Section::make('Details')->compact()->collapsed()->schema([
                TextInput::make('name')->label('Name')->maxLength(100)->placeholder('Block the admin area from abroad'),
                TextInput::make('note')->label('Note')->maxLength(250)->placeholder('Why this rule exists'),
                TextInput::make('priority')->label(FilamentHelp::label('Order', 'Lower numbers run first. New rules go after the existing ones. Use the move buttons in the list to change it later.'))->numeric()
                    ->default(fn (): int => $domain === null ? 100 : (int) ($domain->securityRules()->max('priority') ?? 90) + 10)
                    ->minValue(-1000000)->maxValue(1000000)->required()->placeholder('100'),
                DateTimePicker::make('expires_at')->label(FilamentHelp::label('Ends at', 'The rule stops acting at this time, by each cell\'s own clock, even when the control plane is down. Leave empty for a rule that does not end. At most a year ahead.'))
                    ->seconds(false)->native(false)->minDate(now())->maxDate(now()->addYear())->placeholder('Never'),
                Toggle::make('enabled')->label('Enabled')->default(true),
            ]),
        ];
    }

    /**
     * A button that counts what the rule as written would have matched in the last 24 hours over the stored request fields.
     * It is partial by construction and says so; conditions on fields the log does not store are named as unknown.
     */
    private static function estimate(?Domain $domain): array
    {
        if ($domain === null) {
            return [];
        }

        return [
            Hidden::make('impact_estimate')->dehydrated(false),
            Actions::make([
                Action::make('estimateImpact')->label('Estimate the last 24 hours')->icon('phosphor-chart-bar')->color('gray')->size('sm')
                    ->action(function (Get $get, Set $set) use ($domain): void {
                        $conditions = collect(self::toStored($get('conditions')))->filter(fn ($row): bool => is_array($row) && filled($row['field'] ?? null) && filled($row['operator'] ?? null)
                            && (is_bool($row['value'] ?? null) || filled($row['value'] ?? null)))->values()->all();
                        if ($conditions === []) {
                            $set('impact_estimate', 'Add a condition first. A rate limit alone has nothing to count against the stored fields.');

                            return;
                        }
                        try {
                            $result = SecurityRuleImpact::estimate($domain, $conditions, app(AnalyticsStore::class));
                        } catch (AnalyticsUnavailableException) {
                            $set('impact_estimate', 'Analytics is not answering, so there is no estimate. This does not mean the rule matches nothing.');

                            return;
                        }
                        $set('impact_estimate', $result['matched'] === null
                            ? 'At most '.number_format($result['matched_at_most']).' of '.number_format($result['events_examined']).' requests. Not counted: '.implode(', ', $result['unknown_fields']).' (the log does not store them).'
                            : number_format($result['matched']).' of '.number_format($result['events_examined']).' requests in the last 24 hours. Partial: only what the log stores.');
                    }),
            ]),
            Text::make(fn (Get $get): string => (string) $get('impact_estimate'))->visible(fn (Get $get): bool => filled($get('impact_estimate')))
                ->extraAttributes(['data-testid' => 'rule-impact-estimate', 'class' => 'cdn-rule-reads-as']),
        ];
    }

    /**
     * The condition list of a typed rule. Firewall rules offer every field; request rules pass the smaller set they support.
     *
     * @param  array<string, string>  $unavailable  field => the detection it needs
     * @param  array<string, string>|null  $labels  field => label of the fields to offer, or null for every field
     */
    public static function conditionRepeater(array $unavailable = [], ?array $labels = null, ?array $ipLists = null): Repeater
    {
        return Repeater::make('conditions')->hiddenLabel()
            ->maxItems(SecurityRuleData::MAXIMUM_CONDITIONS)->defaultItems(1)
            ->addActionLabel('Add condition')->reorderable(false)->live()
            ->itemLabel(function (array $state): string {
                $stored = self::toStored([$state])[0];

                return filled($stored['field'] ?? null) && filled($stored['operator'] ?? null) && (is_bool($stored['value'] ?? null) || filled($stored['value'] ?? null))
                    ? SecurityRuleData::describe([$stored]) : 'New condition';
            })
            ->schema(self::conditionFields($unavailable, $labels ?? array_diff_key(SecurityRuleData::FIELD_LABELS, $ipLists === null ? ['client_ip_list' => 1] : []), $ipLists ?? []))->columns(['default' => 1, 'sm' => 2])
            ->formatStateUsing(fn (mixed $state): array => self::toForm($state))->dehydrateStateUsing(fn (mixed $state): array => self::toStored($state));
    }

    private static function rateLimitFields(): array
    {
        $named = fn (?string $key): bool => in_array($key, SecurityRuleData::RATE_NAMED_KEYS, true);

        return [
            Select::make('key')->label(FilamentHelp::label('Count by', 'What one client is: its address, its address on one path, the value of a header such as an API key, a cookie or a query argument, its network (ASN), or its country.'))
                ->options(SecurityRuleData::RATE_KEYS)->default('client_ip')->required()->live()->placeholder('Choose what to count by'),
            Hidden::make('header_name')->dehydrated(false),
            TextInput::make('key_name')->label(fn (Get $get): string => match ($get('key')) {
                'cookie' => 'Cookie name', 'query_arg' => 'Argument name', default => 'Header name',
            })->maxLength(64)->required()->placeholder(fn (Get $get): string => match ($get('key')) {
                'cookie' => 'session', 'query_arg' => 'token', default => 'X-Api-Key',
            })->formatStateUsing(fn (mixed $state, Get $get): mixed => $state ?? $get('header_name'))->visible(fn (Get $get): bool => $named($get('key'))),
            Select::make('second_key')->label(FilamentHelp::label('And also by', 'Counts each combination of the two keys separately, for example a header and a query argument. A request that lacks either part is not counted.'))
                ->options(array_intersect_key(SecurityRuleData::RATE_KEYS, array_flip(SecurityRuleData::RATE_SECOND_KEYS)))->live()->placeholder('Nothing else'),
            TextInput::make('second_name')->label('Second name')->maxLength(64)->required()->placeholder('user')
                ->visible(fn (Get $get): bool => $named($get('second_key'))),
            TextInput::make('threshold')->label(FilamentHelp::label('Requests', 'The most requests one client may send in the period before the action applies. With "Count only responses" it is the most counted responses.'))->numeric()->required()->minValue(1)->maxValue(100000)->placeholder('100'),
            Select::make('period_seconds')->label('Per')->options(SecurityRuleData::RATE_PERIODS)->default(60)->required()->placeholder('Choose a period'),
            Select::make('window')->label(FilamentHelp::label('Window', 'Fixed counts from zero at each period boundary, so a burst on each side of a boundary can carry nearly twice the threshold. Sliding weights the previous period, which holds such a burst to the threshold within 10%.'))
                ->options(SecurityRuleData::RATE_WINDOWS)->default('fixed')->required()->native(false),
            Select::make('scope')->label(FilamentHelp::label('Across cells', 'Counting is always on each cell, never waiting for another. "Each cell counts on its own" admits up to the threshold on every cell serving the domain. "Threshold shared among the cells" gives each cell its share, so balanced traffic stays near the threshold and traffic on one cell is held to its share.'))
                ->options(SecurityRuleData::RATE_SCOPES)->default('per_cell')->required()->native(false),
            TagsInput::make('count_statuses')->label(FilamentHelp::label('Count only responses', 'Leave empty to count every matching request. Type response statuses, such as 401 and 403, to count only those answers, so that successful requests from the same address are never counted.'))
                ->placeholder('401')->helperText('Type a status and press Enter. Up to 10.')->columnSpanFull(),
            TextInput::make('duration_seconds')->label(FilamentHelp::label('Then for (seconds)', 'How long the action keeps applying to that client after it goes over the limit. The client is released when the time is up.'))
                ->numeric()->required()->minValue(10)->maxValue(86400)->default(300)->placeholder('300'),
        ];
    }

    /**
     * Every kind of value has its own input and its own state key, so one input can never reset the value another kind holds.
     * The stored condition has the single key `value`; these two methods move it between that and the form's keys.
     */
    private const VALUE_KEYS = ['value', 'value_number', 'value_list', 'value_country', 'value_continent', 'value_method', 'value_tls', 'value_violation', 'value_iplist', 'value_flag'];

    public static function valueKey(?string $field, ?string $operator): string
    {
        $kind = SecurityRuleData::FIELDS[$field]['kind'] ?? null;
        $list = in_array($operator, ['in_list', 'not_in_list'], true);

        return match (true) {
            $kind === 'flag' => 'value_flag',
            $kind === 'country' => 'value_country',
            $kind === 'continent' => 'value_continent',
            $kind === 'method' => 'value_method',
            $kind === 'tls' => 'value_tls',
            $kind === 'violation' => 'value_violation',
            $kind === 'iplist' => 'value_iplist',
            $list && in_array($kind, ['text', 'address', 'host', 'extension', 'asn', 'score'], true) => 'value_list',
            in_array($kind, ['asn', 'bytes', 'score'], true) => 'value_number',
            default => 'value',
        };
    }

    /** Stored conditions (or a prefill) to the form's per-kind keys. */
    public static function toForm(mixed $conditions): array
    {
        return collect(is_array($conditions) ? $conditions : [])->map(function (mixed $condition): array {
            $condition = is_array($condition) ? $condition : [];
            $key = self::valueKey($condition['field'] ?? null, $condition['operator'] ?? null);
            $value = $condition['value'] ?? null;
            $form = array_diff_key($condition, array_flip(self::VALUE_KEYS));

            return $key === 'value' ? [...$form, 'value' => $value] : [...$form, $key => $value];
        })->all();
    }

    /** The form's per-kind keys back to the stored `value`. */
    public static function toStored(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])->map(function (mixed $item): array {
            $item = is_array($item) ? $item : [];
            $value = $item[self::valueKey($item['field'] ?? null, $item['operator'] ?? null)] ?? null;
            if (is_array($value)) {
                $value = array_values($value);
            }

            return [...array_diff_key($item, array_flip(self::VALUE_KEYS)), 'value' => $value];
        })->values()->all();
    }

    /**
     * @param  array<string, string>  $unavailable  field => the detection it needs
     * @param  array<string, string>  $labels  field => label of the fields to offer
     */
    private static function conditionFields(array $unavailable, array $labels, array $ipLists = []): array
    {
        $kind = fn (Get $get): ?string => SecurityRuleData::FIELDS[$get('field')]['kind'] ?? null;
        $list = fn (Get $get): bool => in_array($get('operator'), ['in_list', 'not_in_list'], true);
        $uses = fn (string $key) => fn (Get $get): bool => self::valueKey($get('field'), $get('operator')) === $key;

        return [
            Select::make('field')->label('Field')->required()->live()->placeholder('Choose a field')
                ->options(collect($labels)->map(fn (string $label, string $field): string => isset($unavailable[$field]) ? "$label (needs {$unavailable[$field]})" : $label)->all())
                ->disableOptionWhen(fn (string $value): bool => isset($unavailable[$value]))
                ->afterStateUpdated(fn (callable $set) => $set('operator', 'equals')),
            Select::make('operator')->label('Operator')->required()->live()->placeholder('Choose how to compare')
                ->options(fn (Get $get): array => collect(SecurityRuleData::FIELDS[$get('field')]['operators'] ?? [])->mapWithKeys(fn (string $operator): array => [$operator => $get('field') === 'client_ip_list' ? ($operator === 'equals' ? 'is on' : 'is not on') : SecurityRuleData::OPERATOR_LABELS[$operator]])->all()),
            TextInput::make('name')->label('Name')->maxLength(64)->required()->placeholder(fn (Get $get): string => $get('field') === 'cookie_present' ? 'session' : 'X-Api-Key')
                ->visible(fn (Get $get): bool => (SecurityRuleData::FIELDS[$get('field')]['named'] ?? false) === true)->columnSpanFull(),
            TextInput::make('value')->label('Value')->required()->maxLength(512)->visible($uses('value'))->columnSpanFull()
                ->placeholder(fn (Get $get): string => self::EXAMPLES[$get('field')] ?? 'Choose a field first'),
            TextInput::make('value_number')->label('Value')->numeric()->required()->visible($uses('value_number'))
                ->placeholder(fn (Get $get): string => match ($kind($get)) {
                    'asn' => '64500', 'score' => '20', default => '1048576'
                })->columnSpanFull(),
            TagsInput::make('value_list')->label('Values')->required()->visible($uses('value_list'))
                ->placeholder(fn (Get $get): string => match ($kind($get)) {
                    'asn' => '64500', 'score' => '10', default => self::EXAMPLES[$get('field')] ?? 'Value'
                })
                ->helperText('Type a value and press Enter. Up to '.SecurityRuleData::listValuesLimit().' values.')->columnSpanFull(),
            Select::make('value_country')->label('Country')->options(GeoVocabulary::countryOptions())->searchable()->multiple($list)->required()
                ->visible($uses('value_country'))->placeholder('Search by name or code')->columnSpanFull(),
            Select::make('value_continent')->label('Continent')->options(GeoVocabulary::continentOptions())->multiple($list)->required()
                ->visible($uses('value_continent'))->placeholder('Choose a continent')->columnSpanFull(),
            Select::make('value_method')->label('Method')->options(array_combine(SecurityRuleData::HTTP_METHODS, SecurityRuleData::HTTP_METHODS))->multiple($list)->required()
                ->visible($uses('value_method'))->placeholder('Choose a method')->columnSpanFull(),
            Select::make('value_tls')->label('TLS version')->options(array_combine(SecurityRuleData::TLS_VERSIONS, SecurityRuleData::TLS_VERSIONS))->multiple($list)->required()
                ->visible($uses('value_tls'))->placeholder('Choose a version')->columnSpanFull(),
            Select::make('value_violation')->label('Violation')->options(self::violationOptions())->multiple($list)->required()
                ->visible($uses('value_violation'))->placeholder('Choose a violation')->columnSpanFull(),
            Select::make('value_iplist')->label('List')->options($ipLists)->required()->visible($uses('value_iplist'))->placeholder($ipLists === [] ? 'Create a list first' : 'Choose a list')
                ->helperText('Lists are managed on the IP lists tab.')->columnSpanFull(),
            Toggle::make('value_flag')->label(FilamentHelp::label('Present', 'On matches requests that have it. Off matches requests that do not.'))->default(true)->visible($uses('value_flag'))->columnSpanFull(),
            Text::make(fn (Get $get): ?string => self::HELP[$get('field')] ?? null)->visible(fn (Get $get): bool => isset(self::HELP[$get('field')]))
                ->color('neutral')->columnSpanFull(),
        ];
    }
}
