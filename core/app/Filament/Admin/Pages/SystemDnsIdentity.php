<?php

namespace App\Filament\Admin\Pages;

use App\Http\Requests\Admin\PlatformDnsSettingsRequest;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\FilamentHelp;
use App\Support\PlatformDnsConfirmation;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class SystemDnsIdentity extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-globe';

    protected static ?string $navigationLabel = 'System DNS identity';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.pages.system-dns-identity';

    public ?array $data = [];

    public ?array $preview = null;

    public ?string $confirmationToken = null;

    public function mount(): void
    {
        $stored = collect(PlatformDnsSetting::query()->find(1)?->toArray())->all() ?: null;
        $stored = $stored === null ? null : $this->withPairRows($stored);
        $this->form->fill($stored ?? [
            'nameservers' => [[], []],
            'soa_refresh' => 3600,
            'soa_retry' => 600,
            'soa_expire' => 1209600,
            'soa_minimum_ttl' => 300,
            'default_ttl' => 300,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Public DNS identity')
                ->description('Canonical platform names used by authoritative DNS and the shared proxy endpoint.')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    TextInput::make('platform_domain')
                        ->label(FilamentHelp::label('Platform domain', 'Enter the public platform domain; standard DNS identity fields will be filled automatically.'))
                        ->required()->maxLength(253)->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                            $domain = mb_strtolower(rtrim(trim((string) $state), '.'));
                            if ($domain === '') {
                                return;
                            }
                            if (blank($get('proxy_hostname'))) {
                                $set('proxy_hostname', "proxy.{$domain}");
                            }
                            $nameservers = $get('nameservers');
                            if (! is_array($nameservers) || collect($nameservers)->every(fn (array $item): bool => blank($item['hostname'] ?? null))) {
                                $set('nameservers', [
                                    ['hostname' => "ns1.{$domain}", 'ipv4' => null, 'ipv6' => null],
                                    ['hostname' => "ns2.{$domain}", 'ipv4' => null, 'ipv6' => null],
                                ]);
                            }
                            if (blank($get('soa_primary'))) {
                                $set('soa_primary', "ns1.{$domain}");
                            }
                            if (blank($get('soa_mailbox'))) {
                                $set('soa_mailbox', "hostmaster.{$domain}");
                            }
                        }),
                    TextInput::make('proxy_hostname')->label('Proxy hostname')->required()->maxLength(220),
                ]),
            Section::make('Authoritative nameservers')
                ->description('At least two public authoritative endpoints. IPv6 is optional but validated when supplied.')
                ->schema([
                    Repeater::make('nameservers')->label('Nameservers')->minItems(2)->maxItems(8)->schema([
                        TextInput::make('hostname')->label('Hostname')->required()->maxLength(253),
                        TextInput::make('ipv4')->label('IPv4')->required()->ipv4(),
                        TextInput::make('ipv6')->label(FilamentHelp::label('IPv6', 'Optional. Leave empty for IPv4-only authoritative DNS.'))->ipv6(),
                    ])->columns(['default' => 1, 'md' => 3]),
                    Repeater::make('customer_pairs')->label('Customer nameserver pairs')
                        ->helperText('Readable pairs offered to customers for delegation, separate from the apex NS set above. Each pair needs two hostnames inside the platform domain with public addresses. A competing claim on a name always gets a different pair, so offer at least two.')
                        ->minItems(1)->maxItems(8)->schema([
                            TextInput::make('first_hostname')->label('First hostname')->required()->maxLength(253),
                            TextInput::make('first_ipv4')->label('First IPv4')->required()->ipv4(),
                            TextInput::make('first_ipv6')->label('First IPv6')->ipv6(),
                            TextInput::make('second_hostname')->label('Second hostname')->required()->maxLength(253),
                            TextInput::make('second_ipv4')->label('Second IPv4')->required()->ipv4(),
                            TextInput::make('second_ipv6')->label('Second IPv6')->ipv6(),
                        ])->columns(['default' => 1, 'md' => 3]),
                    TextEntry::make('hidden_primary')
                        ->label('Hidden primary')
                        ->state(fn (): array => $this->hiddenPrimaries())
                        ->badge()->color('gray')
                        ->placeholder('No enabled DNS primary. Register one under DNS primary first.')
                        ->helperText('The platform SOA, NS, glue, and proxy records are written here. DNS nodes pull the zone from it.'),
                ]),
            Section::make('SOA and TTL policy')
                ->description('Zone authority identity and bounded default timers, in seconds.')
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema([
                    TextInput::make('soa_primary')->label('SOA primary')->required()->maxLength(253),
                    TextInput::make('soa_mailbox')->label('SOA mailbox')->required()->maxLength(253),
                    TextInput::make('soa_refresh')->label('SOA refresh')->required()->integer()->minValue(300)->maxValue(86400),
                    TextInput::make('soa_retry')->label('SOA retry')->required()->integer()->minValue(60)->maxValue(86400),
                    TextInput::make('soa_expire')->label('SOA expire')->required()->integer()->minValue(86400)->maxValue(2419200),
                    TextInput::make('soa_minimum_ttl')->label('SOA minimum TTL')->required()->integer()->minValue(30)->maxValue(86400),
                    TextInput::make('default_ttl')->label('Default TTL')->required()->integer()->minValue(30)->maxValue(86400),
                ]),
        ]);
    }

    public function previewChanges(): void
    {
        $data = PlatformDnsSettingsRequest::validateInput($this->formState());
        $this->form->fill($this->withPairRows($data));
        $this->preview = $data;
        $this->confirmationToken = PlatformDnsConfirmation::issue($data);
        $this->mountAction('confirmDnsIdentity');
    }

    public function confirmDnsIdentityAction(): Action
    {
        return Action::make('confirmDnsIdentity')->icon('phosphor-check-circle')
            ->modalHeading('Review and save DNS identity')
            ->modalDescription('Validation succeeded. Review the normalized desired state before saving and queueing deployment.')
            ->schema([
                Section::make('Validated. Nothing has been saved yet')
                    ->description('The red button writes revisioned desired state and queues an asynchronous DNS deployment.')
                    ->icon('phosphor-check-circle')
                    ->iconColor('success')
                    ->compact(),
                Section::make('Public identity')
                    ->description("These names become the platform's authoritative DNS and shared proxy identity.")
                    ->icon('phosphor-globe')
                    ->schema([
                        TextEntry::make('platform_domain')
                            ->label('Platform domain')
                            ->state(fn (): ?string => $this->preview['platform_domain'] ?? null)
                            ->copyable(),
                        TextEntry::make('proxy_hostname')
                            ->label('Proxy hostname')
                            ->state(fn (): ?string => $this->preview['proxy_hostname'] ?? null)
                            ->copyable(),
                        TextEntry::make('soa_primary')
                            ->label('SOA primary')
                            ->state(fn (): ?string => $this->preview['soa_primary'] ?? null),
                        TextEntry::make('soa_mailbox')
                            ->label('SOA mailbox')
                            ->state(fn (): ?string => $this->preview['soa_mailbox'] ?? null),
                    ])
                    ->columns(['default' => 1, 'md' => 2]),
                Section::make('Authoritative nameservers')
                    ->description('Confirm every public hostname and glue address before deployment.')
                    ->icon('phosphor-hard-drives')
                    ->schema([
                        RepeatableEntry::make('nameservers')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->preview['nameservers'] ?? [])
                            ->table([
                                TableColumn::make('Hostname'),
                                TableColumn::make('IPv4 glue'),
                                TableColumn::make('IPv6 glue'),
                            ])
                            ->schema([
                                TextEntry::make('hostname')->placeholder('Not configured'),
                                TextEntry::make('ipv4')->placeholder('Not configured'),
                                TextEntry::make('ipv6')->placeholder('Not configured'),
                            ]),
                    ]),
                Section::make('Hidden primary')
                    ->description('The desired state is written here; DNS nodes pull it.')
                    ->icon('phosphor-cloud-arrow-up')
                    ->schema([
                        TextEntry::make('hidden_primary')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->hiddenPrimaries())
                            ->badge()
                            ->color('gray')
                            ->placeholder('No enabled DNS primary'),
                    ]),
                Section::make('SOA and TTL timers')
                    ->description('All values are normalized seconds.')
                    ->icon('phosphor-clock')
                    ->schema([
                        TextEntry::make('soa_refresh')
                            ->label('Refresh')
                            ->state(fn (): mixed => $this->preview['soa_refresh'] ?? null)
                            ->suffix(' seconds'),
                        TextEntry::make('soa_retry')
                            ->label('Retry')
                            ->state(fn (): mixed => $this->preview['soa_retry'] ?? null)
                            ->suffix(' seconds'),
                        TextEntry::make('soa_expire')
                            ->label('Expire')
                            ->state(fn (): mixed => $this->preview['soa_expire'] ?? null)
                            ->suffix(' seconds'),
                        TextEntry::make('soa_minimum_ttl')
                            ->label('Minimum TTL')
                            ->state(fn (): mixed => $this->preview['soa_minimum_ttl'] ?? null)
                            ->suffix(' seconds'),
                        TextEntry::make('default_ttl')
                            ->label('Default TTL')
                            ->state(fn (): mixed => $this->preview['default_ttl'] ?? null)
                            ->suffix(' seconds'),
                    ])
                    ->columns(['default' => 2, 'lg' => 5]),
                Section::make('Before you save')
                    ->description('Changing this identity can affect delegation, authoritative answers, and proxy routing. Return to editing if any value is unexpected.')
                    ->icon('phosphor-warning')
                    ->iconColor('danger')
                    ->compact(),
            ])
            ->modalWidth('5xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->closeModalByClickingAway(false)
            ->modalCancelActionLabel('Return to editing')
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->label('Save DNS identity and queue update')
                ->color('danger'))
            ->action(fn () => $this->saveDnsIdentity());
    }

    private function saveDnsIdentity(): void
    {
        $data = PlatformDnsSettingsRequest::validateInput($this->formState());
        abort_unless(PlatformDnsConfirmation::valid($this->confirmationToken, $data), 409, 'Preview and confirm this exact DNS identity payload before applying it.');
        $operation = DB::transaction(function () use ($data): Operation {
            $current = PlatformDnsSetting::query()->lockForUpdate()->find(1);
            $settings = PlatformDnsSetting::query()->updateOrCreate(['id' => 1], [
                ...$data,
                'revision' => ($current?->revision ?? 0) + 1,
            ]);
            $operation = Operation::query()->create([
                'actor_id' => auth()->id(),
                'type' => 'platform_dns_identity.update',
                'status' => 'pending',
                'input' => ['settings_id' => 1, 'revision' => $settings->revision],
            ]);
            AuditLog::record(auth()->user(), 'platform_dns_settings.update_requested', $settings, ['operation_id' => $operation->id, 'revision' => $settings->revision], request()->ip());

            return $operation;
        });
        ApplyPlatformDnsSettings::dispatch($operation->getKey())->afterCommit();
        $this->preview = null;
        $this->confirmationToken = null;
        Notification::make()->success()->title('DNS identity update queued')->body("Operation {$operation->getKey()}")->send();
    }

    /** The form state with the editable pair rows turned back into the typed pair structure. */
    private function formState(): array
    {
        $state = $this->form->getState();
        if (isset($state['customer_pairs'])) {
            $state['customer_nameserver_pairs'] = collect($state['customer_pairs'])->map(fn (array $row): array => [
                ['hostname' => $row['first_hostname'] ?? null, 'ipv4' => $row['first_ipv4'] ?? null, 'ipv6' => $row['first_ipv6'] ?? null],
                ['hostname' => $row['second_hostname'] ?? null, 'ipv4' => $row['second_ipv4'] ?? null, 'ipv6' => $row['second_ipv6'] ?? null],
            ])->values()->all();
        }
        unset($state['customer_pairs']);

        return $state;
    }

    /** @param array<string, mixed> $settings */
    private function withPairRows(array $settings): array
    {
        $settings['customer_pairs'] = collect($settings['customer_nameserver_pairs'] ?? [])->map(fn (array $pair): array => [
            'first_hostname' => $pair[0]['hostname'] ?? null, 'first_ipv4' => $pair[0]['ipv4'] ?? null, 'first_ipv6' => $pair[0]['ipv6'] ?? null,
            'second_hostname' => $pair[1]['hostname'] ?? null, 'second_ipv4' => $pair[1]['ipv4'] ?? null, 'second_ipv6' => $pair[1]['ipv6'] ?? null,
        ])->all();

        return $settings;
    }

    /** @return list<string> the enabled DNS primary (normally one hidden PowerDNS), as "name (target)" */
    private function hiddenPrimaries(): array
    {
        return DnsCluster::query()->where('enabled', true)->orderBy('name')->get()
            ->map(fn (DnsCluster $cluster): string => "{$cluster->name} ({$cluster->apiTarget()})")->all();
    }
}
