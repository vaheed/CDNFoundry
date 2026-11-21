<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Filament\Domain\Resources\Domains\RelationManagers\Concerns\SyncsDomainPage;
use App\Filament\Shared\SecurityRuleForm;
use App\Models\Domain;
use App\Models\RequestRule;
use App\Support\DomainRequestRules;
use App\Support\FilamentHelp;
use App\Support\RequestRuleData;
use App\Support\RequestRulePresets;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A domain's request rules, one tab per type. The cell decides them from the signed snapshot, after every security stage. */
class RequestRulesRelationManager extends RelationManager
{
    use SyncsDomainPage;

    protected static string $relationship = 'requestRules';

    protected static ?string $title = 'Rules';

    private const ORDER_HELP = [
        'redirect' => 'Rules run from the lowest number up. The first matching redirect answers the request.',
        'rewrite' => 'Rules run from the lowest number up. The first matching rewrite changes what the origin receives.',
        'header' => 'Rules run from the lowest number up. Every matching rule applies; a later rule wins for the same header.',
        'cache' => 'Rules run from the lowest number up. The first matching cache rule decides.',
    ];

    private const EMPTY = [
        'redirect' => 'Add a redirect, or start from a preset such as HTTP to HTTPS.',
        'rewrite' => 'Add a rewrite to change the path or query string your origin receives. The visitor\'s address stays the same.',
        'header' => 'Add a header rule, or start from a preset such as the security headers.',
        'cache' => 'Add a cache rule to bypass the cache or set lifetimes for matching requests.',
    ];

    private const PRESET_ICONS = [
        RequestRulePresets::HTTP_TO_HTTPS => 'phosphor-lock-simple', RequestRulePresets::WWW_TO_APEX => 'phosphor-arrow-elbow-down-left',
        RequestRulePresets::APEX_TO_WWW => 'phosphor-arrow-elbow-up-right', RequestRulePresets::MERGE_HOSTS => 'phosphor-arrows-merge',
        RequestRulePresets::MOVE_PATHS => 'phosphor-arrows-left-right', RequestRulePresets::STRIP_PREFIX => 'phosphor-scissors',
        RequestRulePresets::ADD_PREFIX => 'phosphor-plus-square', RequestRulePresets::SECURITY_HEADERS => 'phosphor-shield-check',
        RequestRulePresets::HIDE_ORIGIN_HEADERS => 'phosphor-eye-slash', RequestRulePresets::CORS_STATIC => 'phosphor-globe',
        RequestRulePresets::CACHE_STATIC => 'phosphor-clock', RequestRulePresets::BYPASS_PATHS => 'phosphor-prohibit',
        RequestRulePresets::BYPASS_COOKIES => 'phosphor-cookie',
    ];

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord->requestRules()->count());
    }

    public function getTabs(): array
    {
        return collect(RequestRuleData::TYPE_LABELS)->map(fn (string $label, string $type): Tab => Tab::make($label)
            ->badge(fn (): string => number_format($this->getOwnerRecord()->requestRules()->where('type', $type)->count()))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type)))->all();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'redirect';
    }

    public function form(Schema $schema): Schema
    {
        $type = $this->type();
        $prefixMatch = fn (Get $get): bool => ($type === 'redirect' && $get('action.target') === 'prefix') || ($type === 'rewrite' && $get('action.path') === 'prefix');

        return $schema->columns(1)->components([
            Section::make('When')->description($type === 'header' ? 'Every condition must match. Leave it empty to change every response.' : 'Every condition must match. Up to five.')->compact()->schema([
                SecurityRuleForm::conditionRepeater([], RequestRuleData::fieldLabels())
                    ->defaultItems($type === 'header' ? 0 : 1)
                    ->minItems(fn (Get $get): int => $type === 'header' || $prefixMatch($get) ? 0 : 1)
                    ->required(fn (Get $get): bool => $type !== 'header' && ! $prefixMatch($get)),
            ]),
            Section::make(match ($type) {
                'redirect' => 'Then redirect', 'rewrite' => 'Then rewrite for the origin', 'header' => 'Then change headers', default => 'Then cache',
            })->compact()->schema([
                Group::make(match ($type) {
                    'redirect' => self::redirectFields(), 'rewrite' => self::rewriteFields(), 'header' => self::headerFields(), default => self::cacheFields(),
                })->statePath('action')->columns(['default' => 1, 'sm' => 2]),
            ]),
            Text::make(fn (Get $get): string => self::readsAs($type, $get))->extraAttributes(['data-testid' => 'request-rule-reads-as', 'class' => 'cdn-rule-reads-as']),
            Section::make('Details')->compact()->collapsed()->schema([
                TextInput::make('name')->label('Name')->maxLength(100)->placeholder(match ($type) {
                    'redirect' => 'Old blog to news', 'rewrite' => 'API without its prefix', 'header' => 'Frame protection', default => 'Long cache for images',
                }),
                TextInput::make('priority')->label(FilamentHelp::label('Order', self::ORDER_HELP[$type].' New rules go after the existing ones.'))->numeric()
                    ->default(fn (): int => (int) ($this->domain()->requestRules()->where('type', $type)->max('priority') ?? 90) + 10)
                    ->minValue(-1000000)->maxValue(1000000)->required()->placeholder('100'),
                Toggle::make('enabled')->label('Enabled')->default(true),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        $type = $this->type();
        $noun = RequestRuleData::TYPE_NOUNS[$type];

        return $table->columns([
            TextColumn::make('priority')->label(FilamentHelp::label('Order', self::ORDER_HELP[$type]))->numeric()->sortable(),
            TextColumn::make('name')->label('Rule')
                ->state(fn (RequestRule $record): string => $record->name ?? RequestRuleData::describeConditions($record->type, $record->conditions, $record->action))
                ->description(fn (RequestRule $record): ?string => $record->name === null ? null : RequestRuleData::describeConditions($record->type, $record->conditions, $record->action))
                ->wrap(),
            TextColumn::make('action')->label('Action')
                ->state(fn (RequestRule $record): string => RequestRuleData::describeAction($record->type, $record->action))->wrap(),
            TextColumn::make('enabled')->label('State')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->headerActions([
            CreateAction::make()->label('Add '.$noun)->modalHeading('Add '.$noun)->modalSubmitActionLabel('Add '.$noun)
                ->slideOver()->modalWidth(Width::TwoExtraLarge)->createAnother(false)
                ->using(fn (array $data): RequestRule => DomainRequestRules::create($this->domain(), [...$data, 'type' => $type], auth()->user(), request()->ip())[0]),
            ActionGroup::make([
                ...array_map(fn (string $preset): Action => $this->presetAction($preset), RequestRulePresets::forType($type)),
                ...array_map(fn (string $preset): Action => Action::make('remove_'.$preset)
                    ->label(RequestRulePresets::PRESETS[$preset]['remove'])->icon('phosphor-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => $this->domain()->requestRules()->where('preset', $preset)->exists())
                    ->action(fn () => $this->savePreset($preset, null)), RequestRulePresets::forType($type)),
            ])->label('Presets')->icon('phosphor-sliders-horizontal')->button(),
        ])->recordActions([
            Action::make('moveUp')->label('Move up')->icon('phosphor-arrow-up')
                ->action(fn (RequestRule $record) => DomainRequestRules::move($this->domain(), $record, -1, auth()->user(), request()->ip())),
            Action::make('moveDown')->label('Move down')->icon('phosphor-arrow-down')
                ->action(fn (RequestRule $record) => DomainRequestRules::move($this->domain(), $record, 1, auth()->user(), request()->ip())),
            EditAction::make()->modalHeading('Edit '.$noun)->modalSubmitActionLabel('Save '.$noun)->slideOver()->modalWidth(Width::TwoExtraLarge)
                ->using(function (RequestRule $record, array $data): RequestRule {
                    DomainRequestRules::update($this->domain(), $record, $data, auth()->user(), request()->ip());

                    return $record->refresh();
                }),
            DeleteAction::make()->using(function (RequestRule $record): bool {
                DomainRequestRules::delete($this->domain(), $record, auth()->user(), request()->ip());

                return true;
            }),
        ])->defaultSort('priority')->emptyStateHeading('No '.strtolower(RequestRuleData::TYPE_LABELS[$type]))
            ->emptyStateDescription(self::EMPTY[$type]);
    }

    private function type(): string
    {
        return in_array($this->activeTab, RequestRuleData::TYPES, true) ? $this->activeTab : 'redirect';
    }

    private static function redirectFields(): array
    {
        $target = fn (string ...$values) => fn (Get $get): bool => in_array($get('target'), $values, true);

        return [
            Select::make('target')->label(FilamentHelp::label('Redirect to', 'A fixed URL, the same path on another host, the path with an old prefix replaced, or the same address over HTTPS.'))
                ->options(RequestRuleData::REDIRECT_TARGETS)->default('url')->required()->live()->placeholder('Choose a target')->columnSpanFull(),
            TextInput::make('url')->label(FilamentHelp::label('URL', 'A path on this host, such as /new, or a full http or https address.'))
                ->maxLength(RequestRuleData::MAXIMUM_TARGET_BYTES)->live(onBlur: true)->required()->placeholder('https://www.example.com/new')->visible($target('url'))->columnSpanFull(),
            TextInput::make('host')->label('Host')->maxLength(253)->live(onBlur: true)->required()->placeholder('www.example.com')->visible($target('host')),
            Select::make('scheme')->label('Scheme')->options(['same' => 'Same as the request', 'https' => 'Always HTTPS'])->default('same')->required()
                ->placeholder('Choose a scheme')->visible($target('host')),
            TextInput::make('from_prefix')->label(FilamentHelp::label('Old prefix', 'Requests whose path starts with this prefix are redirected. The rest of the path is kept.'))
                ->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->live(onBlur: true)->required()->placeholder('/blog')->visible($target('prefix')),
            TextInput::make('to_prefix')->label('New prefix')->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->live(onBlur: true)->required()->placeholder('/news')->visible($target('prefix')),
            self::statusSelect(),
            Toggle::make('preserve_query')->label(FilamentHelp::label('Keep the query string', 'Adds the request\'s query string to the target. With a fixed URL that has its own query, both are kept.'))->default(true),
        ];
    }

    private static function rewriteFields(): array
    {
        $path = fn (string $value) => fn (Get $get): bool => $get('path') === $value;

        return [
            Select::make('path')->label(FilamentHelp::label('Path', 'Keep the path, send a fixed path, or replace the prefix the path starts with. The visitor\'s address, the cache, and purge stay on the original URL.'))
                ->options(RequestRuleData::REWRITE_PATHS)->default('prefix')->required()->live()->placeholder('Choose what happens to the path')->columnSpanFull(),
            TextInput::make('path_value')->label('New path')->maxLength(RequestRuleData::MAXIMUM_TARGET_BYTES)->live(onBlur: true)->required()->placeholder('/index.php')
                ->visible($path('set'))->columnSpanFull(),
            TextInput::make('from_prefix')->label(FilamentHelp::label('Old prefix', 'Requests whose path starts with this prefix are rewritten. The rest of the path is kept.'))
                ->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->live(onBlur: true)->required()->placeholder('/api')->visible($path('prefix')),
            TextInput::make('to_prefix')->label(FilamentHelp::label('New prefix', 'Leave empty to remove the old prefix: /api/users becomes /users.'))
                ->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->live(onBlur: true)->placeholder('/v2')->visible($path('prefix')),
            Select::make('query')->label('Query string')->options(RequestRuleData::REWRITE_QUERIES)->default('keep')->required()->live()->placeholder('Choose what happens to the query'),
            TextInput::make('query_value')->label('New query string')->maxLength(RequestRuleData::MAXIMUM_QUERY_BYTES)->live(onBlur: true)->required()->placeholder('lang=en')
                ->visible(fn (Get $get): bool => $get('query') === 'set'),
        ];
    }

    private static function headerFields(): array
    {
        $operations = fn (string $name, string $label, string $help, string $example): Repeater => Repeater::make($name)->label(FilamentHelp::label($label, $help))
            ->schema([
                Select::make('op')->label('Change')->options(RequestRuleData::HEADER_OPERATIONS)->default('set')->required()->live()->placeholder('Set or remove'),
                TextInput::make('name')->label('Header')->required()->maxLength(64)->live(onBlur: true)->placeholder($example),
                TextInput::make('value')->label('Value')->required()->maxLength(RequestRuleData::MAXIMUM_HEADER_VALUE_BYTES)->placeholder($name === 'request' ? 'mobile' : 'SAMEORIGIN')
                    ->visible(fn (Get $get): bool => $get('op') !== 'remove'),
            ])->columns(['default' => 1, 'sm' => 3])->defaultItems(0)->maxItems(RequestRuleData::MAXIMUM_HEADER_OPERATIONS)->addActionLabel('Add header')->reorderable(false)->columnSpanFull();

        return [
            $operations('response', 'Response headers', 'Sent to the visitor, on cached responses too. Host, length, encoding, forwarding, and platform headers cannot be changed.', 'X-Frame-Options'),
            $operations('request', 'Request headers', 'Sent to your origin. They do not change the cache key.', 'X-Client-Type'),
        ];
    }

    private static function cacheFields(): array
    {
        $override = fn (Get $get): bool => $get('mode') === 'override';

        return [
            Select::make('mode')->label(FilamentHelp::label('Cache', 'Bypass sends every matching request to the origin and stores nothing. Otherwise the settings below replace the domain\'s for matching requests; empty ones keep the domain\'s.'))
                ->options(RequestRuleData::CACHE_MODES)->default('override')->required()->live()->placeholder('Choose what happens')->columnSpanFull(),
            TextInput::make('edge_ttl_seconds')->label(FilamentHelp::label('Edge TTL (seconds)', 'How long the edge keeps the response. It replaces the origin\'s own lifetime.'))
                ->numeric()->minValue(0)->maxValue(RequestRuleData::MAXIMUM_TTL_SECONDS)->live(onBlur: true)->placeholder('86400')->visible($override),
            TextInput::make('browser_ttl_seconds')->label(FilamentHelp::label('Browser TTL (seconds)', 'How long visitors keep the response in their browser.'))
                ->numeric()->minValue(0)->maxValue(RequestRuleData::MAXIMUM_TTL_SECONDS)->live(onBlur: true)->placeholder('3600')->visible($override),
            Select::make('respect_origin')->label(FilamentHelp::label('Origin cache headers', 'Whether the origin\'s Cache-Control or Expires sets the edge lifetime when no edge TTL is set here.'))
                ->options(['1' => 'Respect them', '0' => 'Ignore them'])->placeholder('Use the domain setting')->visible($override),
        ];
    }

    private static function statusSelect(): Select
    {
        return Select::make('status_code')->label(FilamentHelp::label('Status', '301 and 308 are permanent and cached by browsers. 302 and 307 are temporary. 307 and 308 keep the method and body of a POST.'))
            ->options(RequestRuleData::REDIRECT_STATUSES)->default(301)->required()->placeholder('Choose a status');
    }

    private static function readsAs(string $type, Get $get): string
    {
        $action = self::formAction($type, (array) $get('action'));
        $conditions = collect(SecurityRuleForm::toStored($get('conditions')))->filter(fn ($row): bool => is_array($row) && filled($row['field'] ?? null) && filled($row['operator'] ?? null)
            && (is_bool($row['value'] ?? null) || filled($row['value'] ?? null)))->values()->all();
        $complete = match ($type) {
            'redirect' => match ($action['target'] ?? null) {
                'url' => filled($action['url'] ?? null), 'host' => filled($action['host'] ?? null),
                'prefix' => filled($action['from_prefix'] ?? null) && filled($action['to_prefix'] ?? null), 'https' => true, default => false,
            },
            'rewrite' => match ($action['path'] ?? null) {
                'set' => filled($action['path_value'] ?? null), 'prefix' => filled($action['from_prefix'] ?? null), default => ($action['query'] ?? 'keep') !== 'keep',
            } && (($action['query'] ?? 'keep') !== 'set' || filled($action['query_value'] ?? null)),
            'header' => collect([...($action['request'] ?? []), ...($action['response'] ?? [])])->contains(fn ($operation): bool => is_array($operation) && filled($operation['name'] ?? null)),
            default => ($action['mode'] ?? null) === 'bypass' || filled($action['edge_ttl_seconds'] ?? null) || filled($action['browser_ttl_seconds'] ?? null) || isset($action['respect_origin']),
        };
        $prefixMatch = ($type === 'redirect' && ($action['target'] ?? null) === 'prefix') || ($type === 'rewrite' && ($action['path'] ?? null) === 'prefix');
        if (! $complete || ($conditions === [] && ! $prefixMatch && $type !== 'header')) {
            return 'Reads as: fill in a condition and what happens to see the rule in words.';
        }
        $verb = match ($type) {
            'redirect' => 'redirect ', 'rewrite' => 'send the origin: ', 'header' => '', default => '',
        };

        return 'Reads as: '.RequestRuleData::describeConditions($type, $conditions, $action).', then '.$verb.lcfirst(RequestRuleData::describeAction($type, $action)).'.';
    }

    /** The form's action state in the stored shape, for the read-back line only. */
    private static function formAction(string $type, array $action): array
    {
        if ($type === 'rewrite') {
            return [...$action, 'to_prefix' => (string) ($action['to_prefix'] ?? '')];
        }
        if ($type === 'header') {
            $clean = fn (mixed $list): array => collect(is_array($list) ? $list : [])->filter(fn ($operation): bool => is_array($operation) && filled($operation['name'] ?? null))
                ->map(fn (array $operation): array => ['op' => $operation['op'] ?? 'set', 'name' => strtolower((string) $operation['name'])])->values()->all();

            return ['request' => $clean($action['request'] ?? []), 'response' => $clean($action['response'] ?? [])];
        }
        if ($type === 'cache') {
            return array_filter([...$action, 'edge_ttl_seconds' => filled($action['edge_ttl_seconds'] ?? null) ? (int) $action['edge_ttl_seconds'] : null,
                'browser_ttl_seconds' => filled($action['browser_ttl_seconds'] ?? null) ? (int) $action['browser_ttl_seconds'] : null,
                'respect_origin' => filled($action['respect_origin'] ?? null) ? $action['respect_origin'] === '1' : null], fn ($value): bool => $value !== null);
        }

        return $action;
    }

    private function presetAction(string $preset): Action
    {
        $domain = $this->domain()->name;
        [$description, $schema] = match ($preset) {
            RequestRulePresets::HTTP_TO_HTTPS => ['Plain HTTP requests answer with a redirect to the same address over HTTPS.', [self::statusSelect()]],
            RequestRulePresets::WWW_TO_APEX => ["www.$domain answers with a redirect to $domain, keeping the path and query string.", [self::statusSelect()]],
            RequestRulePresets::APEX_TO_WWW => ["$domain answers with a redirect to www.$domain, keeping the path and query string.", [self::statusSelect()]],
            RequestRulePresets::MERGE_HOSTS => ['Each listed hostname answers with a redirect to one host, keeping the path and query string.', [
                TagsInput::make('hosts')->label(FilamentHelp::label('Hostnames to redirect', 'Hostnames of this domain that should not serve pages themselves. Type a name and press Enter.'))->required()->placeholder("shop.$domain"),
                TextInput::make('target')->label('Redirect to host')->required()->maxLength(253)->placeholder($domain),
                self::statusSelect(),
            ]],
            RequestRulePresets::MOVE_PATHS => ['Each old prefix redirects to its new one, keeping the rest of the path and the query string.', [
                Repeater::make('moves')->label('Moves')->schema([
                    TextInput::make('from')->label('Old prefix')->required()->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->placeholder('/blog'),
                    TextInput::make('to')->label('New prefix')->required()->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->placeholder('/news'),
                ])->columns(2)->minItems(1)->maxItems(RequestRulePresets::MAXIMUM_ITEMS)->addActionLabel('Add move')->reorderable(false),
                self::statusSelect(),
            ]],
            RequestRulePresets::STRIP_PREFIX => ['Requests under the prefix reach your origin without it: /api/users becomes /users.', [
                TextInput::make('prefix')->label('Prefix')->required()->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->placeholder('/api'),
            ]],
            RequestRulePresets::ADD_PREFIX => ['Every request reaches your origin under the prefix: /users becomes /app/users.', [
                TextInput::make('prefix')->label('Prefix')->required()->maxLength(RequestRuleData::MAXIMUM_PREFIX_BYTES)->placeholder('/app'),
            ]],
            RequestRulePresets::SECURITY_HEADERS => ['Every response carries Strict-Transport-Security, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, and Permissions-Policy.', [
                Select::make('hsts_max_age')->label(FilamentHelp::label('HTTPS only (HSTS)', 'How long browsers refuse plain HTTP for this site. Start short; a long value cannot be taken back early.'))
                    ->options([0 => 'Do not send', 300 => '5 minutes', 86400 => '1 day', 2592000 => '30 days', 31536000 => '1 year', 63072000 => '2 years'])->required()->placeholder('Choose a duration'),
                Toggle::make('hsts_subdomains')->label('Include subdomains'),
                Select::make('frame_options')->label(FilamentHelp::label('Frames', 'Whether other sites may show your pages inside a frame.'))->options(RequestRulePresets::FRAME_OPTIONS)->required()->placeholder('Choose a frame policy'),
                Select::make('referrer_policy')->label('Referrer policy')->options(array_combine(RequestRulePresets::REFERRER_POLICIES, RequestRulePresets::REFERRER_POLICIES))->required()->placeholder('Choose a policy'),
            ]],
            RequestRulePresets::HIDE_ORIGIN_HEADERS => ['Responses no longer show the software your origin runs: '.implode(', ', RequestRulePresets::ORIGIN_SOFTWARE_HEADERS).'.', []],
            RequestRulePresets::CORS_STATIC => ['Files with these extensions may be used by pages on other sites, such as fonts loaded from another domain.', [
                TagsInput::make('extensions')->label('File extensions')->required()->placeholder('woff2')->helperText('Type an extension and press Enter.'),
                TextInput::make('origin')->label(FilamentHelp::label('Allowed origin', 'Use * for any site, or one address such as https://app.example.com.'))->required()->maxLength(253)->placeholder('*'),
            ]],
            RequestRulePresets::CACHE_STATIC => ['Files with these extensions stay longer at the edge and in browsers.', [
                TagsInput::make('extensions')->label('File extensions')->required()->placeholder('png')->helperText('Type an extension and press Enter.'),
                TextInput::make('edge_ttl_days')->label('Edge TTL (days)')->numeric()->minValue(1)->maxValue(365)->required()->placeholder('30'),
                TextInput::make('browser_ttl_days')->label('Browser TTL (days)')->numeric()->minValue(0)->maxValue(365)->required()->placeholder('7'),
            ]],
            RequestRulePresets::BYPASS_PATHS => ['Requests whose path starts with one of these prefixes always reach your origin and are never stored.', [
                TagsInput::make('paths')->label('Path prefixes')->required()->placeholder('/admin')->helperText('Type a prefix and press Enter.'),
            ]],
            default => ['Requests that carry one of these cookies always reach your origin and are never stored, so signed-in pages stay personal.', [
                TagsInput::make('cookies')->label('Cookie names')->required()->placeholder('session')->helperText('Type a cookie name and press Enter.'),
            ]],
        };
        $label = RequestRulePresets::PRESETS[$preset]['label'];

        return Action::make('preset_'.$preset)->label($label)->icon(self::PRESET_ICONS[$preset])
            ->modalHeading($label)->modalDescription($description.' Saving again replaces these rules.')->modalSubmitActionLabel('Save preset')
            ->schema($schema)
            ->fillForm(fn (): array => RequestRulePresets::current($this->domain(), $preset))
            ->action(fn (array $data) => $this->savePreset($preset, $data));
    }

    private function savePreset(string $preset, ?array $data): void
    {
        $domain = $this->domain();
        DomainRequestRules::replacePreset($domain, $preset, $data === null ? null : RequestRulePresets::rules($domain, $preset, $data), auth()->user(), request()->ip());
        Notification::make()->success()->title($data === null ? 'Preset removed' : 'Preset saved')->body('The change is queued in the next signed edge revision.')->send();
    }

    private function domain(): Domain
    {
        $domain = $this->getOwnerRecord();
        assert($domain instanceof Domain);

        return $domain;
    }
}
