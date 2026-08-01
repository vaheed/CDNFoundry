<?php

namespace App\Filament\Admin\Pages;

use App\Http\Requests\Admin\PlatformDnsSettingsRequest;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\FilamentHelp;
use App\Support\PlatformDnsConfirmation;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class SystemDnsIdentity extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'System DNS identity';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.pages.system-dns-identity';

    public ?array $data = [];

    public ?array $preview = null;

    public ?string $confirmationToken = null;

    public function mount(): void
    {
        $this->form->fill(PlatformDnsSetting::query()->find(1)?->toArray() ?? [
            'nameservers' => [[], []],
            'cluster_targets' => [],
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
                    TagsInput::make('cluster_targets')->label('DNS cluster targets')->required()->nestedRecursiveRules(['string', 'max:253']),
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
        $data = PlatformDnsSettingsRequest::validateInput($this->form->getState());
        $this->form->fill($data);
        $this->preview = $data;
        $this->confirmationToken = PlatformDnsConfirmation::issue($data);
        Notification::make()->success()->title('DNS identity validated')->body('Review the normalized preview, then explicitly confirm within 15 minutes.')->send();
    }

    public function save(): void
    {
        $data = PlatformDnsSettingsRequest::validateInput($this->form->getState());
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
}
