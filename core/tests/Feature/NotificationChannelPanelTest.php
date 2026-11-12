<?php

namespace Tests\Feature;

use App\Filament\Shared\Pages\NotificationChannels;
use App\Jobs\TestNotificationChannel;
use App\Models\NotificationChannel;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationChannelPanelTest extends TestCase
{
    use RefreshDatabase;

    private function switchUserChannels(bool $on): void
    {
        $row = SystemSetting::query()->findOrFail('notifications');
        $row->update(['values' => [...$row->values, 'domain_user_channels' => $on]]);
        app()->forgetInstance(PlatformSettings::class);
    }

    public function test_an_administrator_adds_tests_and_removes_a_platform_channel(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(NotificationChannels::class)
            ->set('type', 'telegram')->set('name', 'Ops chat')->set('botToken', '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')->set('chatId', '-1001234567890')
            ->call('addChannel')->assertHasNoErrors()->assertSee('Ops chat');
        $channel = NotificationChannel::query()->firstOrFail();
        $this->assertNull($channel->user_id);
        $this->assertNull($channel->events, 'all events selected stores no list');

        Livewire::test(NotificationChannels::class)->call('testChannel', $channel->id);
        Queue::assertPushed(TestNotificationChannel::class);
        Livewire::test(NotificationChannels::class)->call('removeChannel', $channel->id);
        $this->assertSame(0, NotificationChannel::query()->count());
    }

    public function test_the_webhook_signing_secret_is_shown_once(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());
        $page = Livewire::test(NotificationChannels::class)
            ->set('type', 'webhook')->set('name', 'Pager')->set('webhookUrl', 'https://93.184.216.34/hook')->call('addChannel')->assertHasNoErrors();
        $secret = $page->get('signingSecret');
        $this->assertSame(48, strlen($secret));
        $page->assertSee($secret);
        Livewire::test(NotificationChannels::class)->assertDontSee($secret)->assertSee('Pager');
    }

    public function test_the_domain_panel_page_exists_only_while_the_switch_is_on_and_scopes_to_the_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $this->assertFalse(NotificationChannels::canAccess());
        Livewire::test(NotificationChannels::class)->assertForbidden();

        $this->switchUserChannels(true);
        $this->assertTrue(NotificationChannels::canAccess());
        $theirs = new NotificationChannel(['user_id' => $other->id, 'type' => 'telegram', 'name' => 'Theirs', 'destination_hint' => 'x', 'events' => ['certificate.failed'], 'status' => 'active', 'consecutive_failures' => 0]);
        $theirs->storeSecrets(['bot_token' => 'x', 'chat_id' => '1']);
        $theirs->save();

        $page = Livewire::test(NotificationChannels::class)
            ->set('type', 'telegram')->set('name', 'Mine')->set('botToken', '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')->set('chatId', '-1001234567890')
            ->set('events', [])->call('addChannel')->assertHasErrors('events');
        $page->set('events', ['certificate.failed'])->call('addChannel')->assertHasNoErrors()->assertSee('Mine')->assertDontSee('Theirs');
        $page->assertDontSee('Platform alert');
        Livewire::test(NotificationChannels::class)->call('removeChannel', $theirs->id)->assertStatus(404);
        $this->assertDatabaseHas('notification_channels', ['id' => $theirs->id]);

        $this->switchUserChannels(false);
        $this->assertFalse(NotificationChannels::canAccess());
    }
}
