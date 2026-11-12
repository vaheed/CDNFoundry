@php($admin = \App\Filament\Shared\Pages\NotificationChannels::isAdminPanel())
@php($help = fn (string $label, string $text) => new \Illuminate\Support\HtmlString('<span class="cursor-help" tabindex="0" x-tooltip="{ content: '.\Illuminate\Support\Js::from($text)->toHtml().', theme: $store.theme }">'.e($label).'</span>'))
<x-filament-panels::page>
    @if ($signingSecret)
        <x-filament::section heading="Copy the signing secret" description="It is shown only this once. Your receiver uses it to check the X-CDNFoundry-Signature header." icon="phosphor-key" data-testid="notification-secret">
            <div class="flex items-start gap-2" x-data="{ done: false }">
                <code class="cdn-secret-value min-w-0 flex-1" data-testid="notification-secret-value">{{ $signingSecret }}</code>
                <x-filament::icon-button icon="phosphor-copy" label="Copy signing secret" color="gray" data-testid="notification-secret-copy"
                    x-on:click="navigator.clipboard.writeText(@js($signingSecret)); done = true; setTimeout(() => done = false, 1500)" />
                <span class="sr-only" role="status" x-text="done ? 'Copied' : ''"></span>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Add a channel" description="Choose where messages go and which events you want." data-testid="notification-create">
        <form wire:submit="addChannel" class="cdn-token-form">
            <div class="cdn-token-field">
                <label class="cdn-field-label" for="channel-type">{{ $help('Type', 'Where messages are sent. Telegram needs a bot token and chat ID, Discord and Slack need an incoming webhook address.'.($admin ? ' A webhook posts signed JSON to your own receiver.' : '')) }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="channel-type" wire:model.live="type" data-testid="notification-type">
                        @foreach ($this->typeChoices as $choice)
                            <option value="{{ $choice }}">{{ match ($choice) { 'telegram' => 'Telegram', 'discord' => 'Discord', 'slack' => 'Slack', default => 'Webhook' } }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div class="cdn-token-field">
                <label class="cdn-field-label" for="channel-name">Name</label>
                <x-filament::input.wrapper :valid="! $errors->has('name')">
                    <x-filament::input id="channel-name" wire:model="name" placeholder="Operations chat" maxlength="100" autocomplete="off" data-testid="notification-name" />
                </x-filament::input.wrapper>
                @error('name') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            @if ($type === 'telegram')
                <div class="cdn-token-field">
                    <label class="cdn-field-label" for="channel-bot-token">{{ $help('Bot token', 'The token BotFather gave your bot. It is stored encrypted and never shown again.') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('bot_token')">
                        <x-filament::input id="channel-bot-token" type="password" wire:model="botToken" placeholder="123456789:AAExampleTokenFromBotFatherxxxxxxxx" autocomplete="off" data-testid="notification-bot-token" />
                    </x-filament::input.wrapper>
                    @error('bot_token') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="cdn-token-field">
                    <label class="cdn-field-label" for="channel-chat-id">{{ $help('Chat ID', 'The number of the chat or group the bot posts to (groups start with a minus sign), or a channel name that starts with @.') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('chat_id')">
                        <x-filament::input id="channel-chat-id" wire:model="chatId" placeholder="-1001234567890" autocomplete="off" data-testid="notification-chat-id" />
                    </x-filament::input.wrapper>
                    @error('chat_id') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @else
                <div class="cdn-token-field cdn-token-field--wide">
                    <label class="cdn-field-label" for="channel-url">{{ $help($type === 'webhook' ? 'Receiver URL' : 'Webhook URL', $type === 'webhook' ? 'An address on the public internet that accepts a JSON POST. Private, loopback, and platform addresses are refused. It is stored encrypted.' : 'The incoming webhook address from your '.ucfirst($type).' settings. It is stored encrypted and never shown again.') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('webhook_url')">
                        <x-filament::input id="channel-url" type="password" wire:model="webhookUrl" autocomplete="off" data-testid="notification-url"
                            placeholder="{{ match ($type) { 'discord' => 'https://discord.com/api/webhooks/123456789012345678/abcdEFGH…', 'slack' => 'https://hooks.slack.com/services/T0000000/B0000000/abcdEFGH…', default => 'https://alerts.example.com/cdnfoundry' } }}" />
                    </x-filament::input.wrapper>
                    @error('webhook_url') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif
            <fieldset class="cdn-token-field cdn-token-field--wide" data-testid="notification-events">
                <legend class="cdn-field-label">{{ $help('Events', $admin ? 'Administrator channels receive every platform alert and every domain event. Narrow the list to send fewer.' : 'Only events for your own domains are sent, never another customer\'s.') }}</legend>
                <ul class="cdn-token-domain-list cdn-token-domain-list--all">
                    @foreach ($this->eventChoices as $key => $label)
                        <li>
                            <label class="cdn-token-domain">
                                <x-filament::input.checkbox wire:model="events" value="{{ $key }}" />
                                <span class="truncate">{{ $label }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                @error('events') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
                @error('events.*') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
            </fieldset>
            <div class="cdn-token-actions">
                <x-filament::button type="submit" icon="phosphor-plus" wire:loading.attr="disabled" wire:target="addChannel" data-testid="notification-add-button">
                    <span wire:loading.remove wire:target="addChannel">Add channel</span>
                    <span wire:loading wire:target="addChannel">Adding…</span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Channels">
        <div class="cdn-resource-list">
            @forelse ($this->channels as $channel)
                <x-ui.list-row :title="$channel->name" data-testid="notification-row">
                    <ul class="cdn-chips mt-1">
                        <li class="cdn-chip">{{ ucfirst($channel->type) }}</li>
                        <li class="cdn-chip">{{ $channel->destination_hint }}</li>
                        <li class="cdn-chip">{{ $channel->events === null ? 'Every event' : trans_choice(':count event|:count events', count($channel->events)) }}</li>
                        <li class="cdn-chip">
                            <x-ui.status-pill :tone="$channel->status === 'active' ? 'success' : 'warning'">{{ $channel->status === 'active' ? 'Active' : 'Paused' }}</x-ui.status-pill>
                        </li>
                        @if ($channel->last_success_at)<li class="cdn-chip">Last sent <span title="{{ $channel->last_success_at->toDateTimeString() }}">{{ $channel->last_success_at->diffForHumans() }}</span></li>@endif
                    </ul>
                    @if ($channel->status === 'paused' && $channel->paused_reason)
                        <p class="cdn-field-error mt-1" role="status" data-testid="notification-paused-reason">{{ $channel->paused_reason }}</p>
                    @elseif ($channel->last_error)
                        <p class="cdn-row-meta mt-1">Last error: {{ $channel->last_error }}</p>
                    @endif
                    <x-slot name="aside">
                        @if ($channel->status === 'paused')
                            <x-filament::button color="gray" outlined size="sm" wire:click="resumeChannel('{{ $channel->id }}')" data-testid="notification-resume">Resume</x-filament::button>
                        @endif
                        <x-filament::button color="gray" outlined size="sm" wire:click="testChannel('{{ $channel->id }}')" wire:loading.attr="disabled" wire:target="testChannel('{{ $channel->id }}')" data-testid="notification-test">Send test</x-filament::button>
                        <x-filament::button color="danger" outlined size="sm" wire:click="removeChannel('{{ $channel->id }}')" wire:confirm="Remove this channel?" data-testid="notification-remove">Remove</x-filament::button>
                    </x-slot>
                </x-ui.list-row>
            @empty
                <x-ui.empty-state icon="phosphor-bell-ringing" title="No channels yet" description="Add a channel to receive messages outside the panel." />
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
