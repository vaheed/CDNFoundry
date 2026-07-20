<x-filament-panels::page>
    @if ($plainTextToken)
        <x-filament::section heading="New token — copy now" description="This secret is shown once. Store it before leaving this page." icon="heroicon-o-key">
            <code class="cdn-secret-value">{{ $plainTextToken }}</code>
        </x-filament::section>
    @endif

    <x-filament::section heading="Create token">
        <form wire:submit="createToken" class="cdn-inline-form">
            <div class="min-w-0 flex-1">
                <label class="cdn-field-label" for="token-name">Token name</label>
                <x-filament::input.wrapper :valid="! $errors->has('name')">
                    <x-filament::input id="token-name" wire:model="name" placeholder="Deployment automation" maxlength="100" autocomplete="off" />
                </x-filament::input.wrapper>
                @error('name') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="createToken">
                <span wire:loading.remove wire:target="createToken">Create token</span>
                <span wire:loading wire:target="createToken">Creating…</span>
            </x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section heading="Active tokens">
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($this->tokens as $token)
                <div class="flex items-center justify-between py-3">
                    <div>
                        <strong>{{ $token->name }}</strong>
                        <div class="text-sm text-gray-500">
                            @if ($token->token_last_six)
                                Ending in <span class="font-mono">{{ $token->token_last_six }}</span> ·
                            @endif
                            Created {{ $token->created_at?->diffForHumans() }}
                        </div>
                    </div>
                    <x-filament::button color="danger" size="sm" wire:click="revokeToken({{ $token->id }})" wire:loading.attr="disabled" wire:target="revokeToken({{ $token->id }})" wire:confirm="Revoke this token?">Revoke</x-filament::button>
                </div>
            @empty
                <x-ui.empty-state title="No active API tokens" description="Create a named token only when an integration needs API access." />
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
