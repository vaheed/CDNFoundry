<?php

namespace App\Filament\Shared\Pages;

use App\Jobs\TestNotificationChannel;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\Operation;
use App\Support\Notifications\ChannelData;
use App\Support\Notifications\NotificationEvents;
use App\Support\PlatformSettings;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where notifications go. In the administrator panel these are the platform's channels and receive everything; in the
 * domain panel they are the account's own channels, available only while the administrator switch is on.
 */
class NotificationChannels extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-bell-ringing';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notifications';

    protected static ?string $slug = 'notifications';

    protected string $view = 'filament.shared.pages.notification-channels';

    public string $type = 'telegram';

    public string $name = '';

    public string $botToken = '';

    public string $chatId = '';

    public string $webhookUrl = '';

    /** @var list<string> */
    public array $events = [];

    public ?string $signingSecret = null;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return self::isAdminPanel() ? 'Governance' : 'Account';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || $user->isDisabled()) {
            return false;
        }

        return self::isAdminPanel() ? $user->isAdmin() : ! $user->isAdmin() && app(PlatformSettings::class)->get('notifications', 'domain_user_channels') === true;
    }

    public static function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    public function getSubheading(): ?string
    {
        return self::isAdminPanel() ? 'Platform channels receive every alert and every domain event.' : 'Channels receive events for your domains only.';
    }

    public function mount(): void
    {
        $this->events = array_keys($this->eventChoices);
    }

    /** @return array<string, string> */
    public function getEventChoicesProperty(): array
    {
        return self::isAdminPanel() ? NotificationEvents::all() : NotificationEvents::forDomainUsers();
    }

    /** @return list<string> */
    public function getTypeChoicesProperty(): array
    {
        return self::isAdminPanel() ? NotificationChannel::TYPES : ['telegram', 'discord', 'slack'];
    }

    public function getChannelsProperty(): Collection
    {
        return NotificationChannel::query()->where('user_id', self::isAdminPanel() ? null : auth()->id())->orderBy('created_at')->limit(100)->get();
    }

    public function addChannel(): void
    {
        $this->resetErrorBag();
        $owner = self::isAdminPanel() ? null : auth()->user();
        $input = ['type' => $this->type, 'name' => $this->name, 'events' => $this->events === [] ? ($owner === null ? null : []) : $this->events,
            'bot_token' => $this->botToken, 'chat_id' => $this->chatId, 'webhook_url' => $this->webhookUrl];
        // Administrators may keep every event, which stores no list.
        if ($owner === null && count($this->events) === count($this->eventChoices)) {
            $input['events'] = null;
        }
        try {
            $data = ChannelData::validate($input, $owner);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }
        try {
            DB::transaction(function () use ($owner, $data): void {
                DB::table('system_settings')->where('group', 'notifications')->lockForUpdate()->first();
                ChannelData::assertRoom($owner);
                $channel = new NotificationChannel(['user_id' => $owner?->getKey(), 'type' => $data['type'], 'name' => $data['name'], 'destination_hint' => $data['hint'], 'events' => $data['events'], 'status' => 'active', 'consecutive_failures' => 0]);
                $channel->storeSecrets($data['secrets']);
                $channel->save();
                AuditLog::record(auth()->user(), 'notification_channel.created', $channel, ['type' => $channel->type, 'platform' => $owner === null], request()->ip());
            });
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }
        $this->signingSecret = $data['signing_secret'];
        $this->reset('name', 'botToken', 'chatId', 'webhookUrl');
        Notification::make()->success()->title('Channel added')->body($this->signingSecret === null ? 'Send a test message to confirm it delivers.' : 'Copy the signing secret now; it is shown only once.')->send();
    }

    public function removeChannel(string $id): void
    {
        $channel = $this->owned($id);
        $channel->delete();
        AuditLog::record(auth()->user(), 'notification_channel.deleted', null, ['channel_id' => $id, 'type' => $channel->type, 'platform' => $channel->isPlatform()], request()->ip());
        Notification::make()->success()->title('Channel removed')->send();
    }

    public function resumeChannel(string $id): void
    {
        $this->owned($id)->forceFill(['status' => 'active', 'consecutive_failures' => 0, 'paused_reason' => null])->save();
        Notification::make()->success()->title('Channel resumed')->send();
    }

    public function testChannel(string $id): void
    {
        $channel = $this->owned($id);
        $operation = Operation::receipt('notification.test', ['channel_id' => $channel->id, 'user_id' => auth()->id()], auth()->id(), 'pending');
        TestNotificationChannel::dispatch($operation->id);
        Notification::make()->success()->title('Test message queued')->body("Operation {$operation->id}. The result appears on the channel.")->send();
    }

    private function owned(string $id): NotificationChannel
    {
        $channel = NotificationChannel::query()->where('user_id', self::isAdminPanel() ? null : auth()->id())->findOrFail($id);
        abort_unless(self::canAccess(), 403);

        return $channel;
    }
}
