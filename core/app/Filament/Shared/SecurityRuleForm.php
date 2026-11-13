<?php

namespace App\Filament\Shared;

use App\Support\FilamentHelp;
use App\Support\GeoVocabulary;
use App\Support\SecurityRuleData;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/** The fields of one typed security rule, shared by a domain's custom rules and the rules of a platform ruleset. */
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
        'prompt_injection_score' => 'From 1 (two or more known injection phrases) or 29 (one phrase) to 99 (none), from fixed phrase patterns on the prompt of your AI endpoints. It is pattern based and does not understand the prompt. Needs AI protection set up.',
        'prompt_pii' => 'Whether the prompt holds an email address, payment card number, IBAN, or US social security number. Needs AI protection set up.',
        'schema_violation' => 'How the request differs from the uploaded API schema: an unknown path, a method the path does not allow, a missing or wrongly typed parameter, or a JSON body over the limit. "Any" matches every violation. Needs an API schema.',
        'leaked_password' => 'Whether the password sent to one of the domain\'s login paths is in the list of known leaked passwords. Needs login paths set in Credential check.',
    ];

    /** @return array<string, string> */
    private static function violationOptions(): array
    {
        return ['any' => 'Any violation', 'none' => 'No violation', 'unknown_path' => 'Unknown path', 'wrong_method' => 'Wrong method', 'missing_parameter' => 'Missing parameter',
            'invalid_parameter' => 'Wrongly typed parameter', 'body_too_large' => 'JSON body too large'];
    }

    public static function fields(bool $withPreview = true): array
    {
        $fields = [
            TextInput::make('name')->label('Name')->maxLength(100)->placeholder('Block the admin area from abroad'),
            Repeater::make('conditions')->label(FilamentHelp::label('Conditions', 'Every condition must match for the rule to apply. Up to five.'))
                ->minItems(fn (Get $get): int => $get('rate_limited') ? 0 : 1)->maxItems(SecurityRuleData::MAXIMUM_CONDITIONS)->defaultItems(1)->addActionLabel('Add condition')->live()
                ->schema(self::conditionFields())->columns(2)->required(fn (Get $get): bool => ! $get('rate_limited'))
                ->formatStateUsing(fn (mixed $state): array => self::toForm($state))->dehydrateStateUsing(fn (mixed $state): array => self::toStored($state)),
            Toggle::make('rate_limited')->label(FilamentHelp::label('Limit the request rate', 'Count the requests that match, and apply the action only once a client goes over the limit. Counted separately on each cell, not as one global count across locations.'))->live()
                ->afterStateHydrated(fn (Toggle $component, mixed $record) => $component->state(filled(is_array($record) ? ($record['rate_limit'] ?? null) : $record?->rate_limit)))->dehydrated(false)->default(false),
            Group::make(self::rateLimitFields())->statePath('rate_limit')->visible(fn (Get $get): bool => (bool) $get('rate_limited')),
            Select::make('action')->label(FilamentHelp::label('Action', 'Block answers 403 (429 for a rate limit). Challenge asks a browser for a short proof of work. Log records an event and continues. Allow stops all later rules and skips the challenge. Skip managed rules turns the managed WAF off for the request.'))
                ->options(fn (Get $get): array => $get('rate_limited') ? array_intersect_key(SecurityRuleData::ACTION_LABELS, array_flip(SecurityRuleData::RATE_ACTIONS)) : SecurityRuleData::ACTION_LABELS)->required()->placeholder('Choose what happens'),
            TextInput::make('priority')->label(FilamentHelp::label('Order', 'Lower numbers run first. Use the move buttons in the list to change it later.'))->numeric()->default(100)
                ->minValue(-1000000)->maxValue(1000000)->required()->placeholder('100'),
            Toggle::make('enabled')->label('Enabled')->default(true),
            TextInput::make('note')->label('Note')->maxLength(250)->placeholder('Why this rule exists'),
        ];
        if ($withPreview) {
            $fields[] = Placeholder::make('reads_as')->label('Reads as')->content(function (Get $get): HtmlString {
                $conditions = collect(self::toStored($get('conditions')))->filter(fn ($row): bool => is_array($row) && filled($row['field'] ?? null) && filled($row['operator'] ?? null)
                    && (is_bool($row['value'] ?? null) || filled($row['value'] ?? null)))->values()->all();
                $action = SecurityRuleData::ACTION_LABELS[$get('action')] ?? null;

                return new HtmlString(e($conditions === [] ? 'Fill in a condition to preview the rule.' : (SecurityRuleData::describe($conditions).($action ? ' → '.$action : ''))));
            });
        }

        return $fields;
    }

    private static function rateLimitFields(): array
    {
        return [
            Select::make('key')->label(FilamentHelp::label('Count by', 'What one client is: its address, its address on one path, or the value of a header such as an API key.'))
                ->options(SecurityRuleData::RATE_KEYS)->default('client_ip')->required()->live()->placeholder('Choose what to count by'),
            TextInput::make('header_name')->label('Header name')->maxLength(64)->required()->placeholder('X-Api-Key')
                ->visible(fn (Get $get): bool => $get('key') === 'header'),
            TextInput::make('threshold')->label(FilamentHelp::label('Requests', 'The most requests one client may send in the period before the action applies.'))->numeric()->required()->minValue(1)->maxValue(100000)->placeholder('100'),
            Select::make('period_seconds')->label('Per')->options(SecurityRuleData::RATE_PERIODS)->default(60)->required()->placeholder('Choose a period'),
            TextInput::make('duration_seconds')->label(FilamentHelp::label('Then for (seconds)', 'How long the action keeps applying to that client after it goes over the limit. The client is released when the time is up.'))
                ->numeric()->required()->minValue(10)->maxValue(86400)->default(300)->placeholder('300'),
        ];
    }

    /**
     * Every kind of value has its own input and its own state key, so one input can never reset the value another kind holds.
     * The stored condition has the single key `value`; these two methods move it between that and the form's keys.
     */
    private const VALUE_KEYS = ['value', 'value_number', 'value_list', 'value_country', 'value_continent', 'value_method', 'value_tls', 'value_violation', 'value_flag'];

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

    private static function conditionFields(): array
    {
        $kind = fn (Get $get): ?string => SecurityRuleData::FIELDS[$get('field')]['kind'] ?? null;
        $list = fn (Get $get): bool => in_array($get('operator'), ['in_list', 'not_in_list'], true);
        $uses = fn (string $key) => fn (Get $get): bool => self::valueKey($get('field'), $get('operator')) === $key;

        return [
            Select::make('field')->label('Field')->options(SecurityRuleData::FIELD_LABELS)->required()->live()->placeholder('Choose a field')
                ->afterStateUpdated(fn (callable $set) => $set('operator', 'equals'))
                ->helperText(fn (Get $get): ?string => self::HELP[$get('field')] ?? null),
            Select::make('operator')->label('Operator')->required()->live()->placeholder('Choose how to compare')
                ->options(fn (Get $get): array => collect(SecurityRuleData::FIELDS[$get('field')]['operators'] ?? [])->mapWithKeys(fn (string $operator): array => [$operator => SecurityRuleData::OPERATOR_LABELS[$operator]])->all()),
            TextInput::make('name')->label('Name')->maxLength(64)->required()->placeholder(fn (Get $get): string => $get('field') === 'cookie_present' ? 'session' : 'X-Api-Key')
                ->visible(fn (Get $get): bool => (SecurityRuleData::FIELDS[$get('field')]['named'] ?? false) === true)->columnSpanFull(),
            TextInput::make('value')->label('Value')->required()->maxLength(512)->visible($uses('value'))->columnSpanFull()
                ->placeholder(fn (Get $get): string => self::EXAMPLES[$get('field')] ?? 'Value'),
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
            Toggle::make('value_flag')->label(FilamentHelp::label('Present', 'On matches requests that have it. Off matches requests that do not.'))->default(true)->visible($uses('value_flag'))->columnSpanFull(),
        ];
    }
}
