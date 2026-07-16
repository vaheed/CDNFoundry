<?php

namespace App\Filament\Admin\Pages;

use App\Http\Requests\Admin\PlatformDnsSettingsRequest;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;

class SystemDnsIdentity extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'System DNS identity';

    protected string $view = 'filament.admin.pages.system-dns-identity';

    public ?array $data = [];

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
            TextInput::make('platform_domain')->required()->maxLength(253),
            TextInput::make('proxy_hostname')->required()->maxLength(253),
            Repeater::make('nameservers')->minItems(2)->maxItems(8)->schema([
                TextInput::make('hostname')->required()->maxLength(253),
                TextInput::make('ipv4')->required()->ipv4(),
                TextInput::make('ipv6')->required()->ipv6(),
            ])->columns(3),
            TextInput::make('soa_primary')->required()->maxLength(253),
            TextInput::make('soa_mailbox')->required()->maxLength(253),
            TextInput::make('soa_refresh')->required()->integer()->minValue(300)->maxValue(86400),
            TextInput::make('soa_retry')->required()->integer()->minValue(60)->maxValue(86400),
            TextInput::make('soa_expire')->required()->integer()->minValue(86400)->maxValue(2419200),
            TextInput::make('soa_minimum_ttl')->required()->integer()->minValue(30)->maxValue(86400),
            TextInput::make('default_ttl')->required()->integer()->minValue(30)->maxValue(86400),
            TagsInput::make('cluster_targets')->required()->nestedRecursiveRules(['string', 'max:253']),
        ])->columns(2);
    }

    public function save(): void
    {
        $data = Validator::make(
            $this->form->getState(),
            (new PlatformDnsSettingsRequest)->rules(),
        )->validate();
        $operation = Operation::query()->create([
            'actor_id' => auth()->id(),
            'type' => 'platform_dns_identity.update',
            'status' => 'pending',
            'input' => $data,
        ]);
        AuditLog::record(auth()->user(), 'platform_dns_settings.update_requested', $operation, [], request()->ip());
        ApplyPlatformDnsSettings::dispatch($operation->getKey());
        Notification::make()->success()->title('DNS identity update queued')->body("Operation {$operation->getKey()}")->send();
    }
}
