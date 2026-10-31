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
            <div>
                <label class="cdn-field-label" for="token-ability">Access</label>
                <x-filament::input.wrapper :valid="! $errors->has('ability')">
                    <x-filament::input.select id="token-ability" wire:model="ability">
                        <option value="*">Full access</option>
                        <option value="read">Read only</option>
                        <option value="dns">DNS records only</option>
                        <option value="purge">Cache purge only</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div>
                <label class="cdn-field-label" for="token-lifetime">Expires after (days)</label>
                <x-filament::input.wrapper :valid="! $errors->has('lifetimeDays')">
                    <x-filament::input id="token-lifetime" type="number" min="1" max="365" wire:model="lifetimeDays" />
                </x-filament::input.wrapper>
                @error('lifetimeDays') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="min-w-0">
                <label class="cdn-field-label" for="token-domains">Limit to domains (optional)</label>
                <select id="token-domains" wire:model="domainIds" multiple size="4" class="fi-input block w-full rounded-lg border-gray-300 text-sm">
                    @foreach ($this->domainChoices as $choice)
                        <option value="{{ $choice->id }}">{{ $choice->name }}</option>
                    @endforeach
                </select>
                @error('domainIds.*') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
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
                            {{ in_array('*', $token->abilities ?? [], true) ? 'Full access' : implode(', ', $token->abilities ?? []) }} ·
                            @if ($token->domains()->exists()) {{ $token->domains()->count() }} domain(s) · @endif
                            Expires {{ $token->expires_at?->diffForHumans() ?? 'never' }} ·
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
