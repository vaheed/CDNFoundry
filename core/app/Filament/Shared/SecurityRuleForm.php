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
use Illuminate\Database\Eloquent\Model;
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
    ];

    public static function fields(bool $withPreview = true): array
    {
        $fields = [
            TextInput::make('name')->label('Name')->maxLength(100)->placeholder('Block the admin area from abroad'),
            Repeater::make('conditions')->label(FilamentHelp::label('Conditions', 'Every condition must match for the rule to apply. Up to five.'))
                ->minItems(fn (Get $get): int => $get('rate_limited') ? 0 : 1)->maxItems(SecurityRuleData::MAXIMUM_CONDITIONS)->defaultItems(1)->addActionLabel('Add condition')->live()
                ->schema(self::conditionFields())->columns(2)->required(fn (Get $get): bool => ! $get('rate_limited')),
            Toggle::make('rate_limited')->label(FilamentHelp::label('Limit the request rate', 'Count the requests that match, and apply the action only once a client goes over the limit. Counted separately on each cell, not as one global count across locations.'))->live()
                ->afterStateHydrated(fn (Toggle $component, ?Model $record) => $component->state(filled($record?->rate_limit)))->dehydrated(false)->default(false),
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
                $conditions = collect($get('conditions') ?? [])->filter(fn ($row): bool => is_array($row) && filled($row['field'] ?? null) && filled($row['operator'] ?? null)
                    && (is_bool($row['value'] ?? null) || filled($row['value'] ?? null)))->all();
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

    private static function conditionFields(): array
    {
        $kind = fn (Get $get): ?string => SecurityRuleData::FIELDS[$get('field')]['kind'] ?? null;
        $list = fn (Get $get): bool => in_array($get('operator'), ['in_list', 'not_in_list'], true);
        $text = fn (Get $get): bool => in_array($kind($get), ['text', 'address', 'host', 'extension'], true) && ! $list($get);
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
            TagsInput::make('value')->label('Values')->required()->visible(fn (Get $get): bool => $list($get) && in_array($kind($get), ['text', 'address', 'host', 'extension', 'asn', 'score'], true))
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
}
