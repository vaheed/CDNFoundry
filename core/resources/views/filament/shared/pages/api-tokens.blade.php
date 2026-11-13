<x-filament-panels::page>
    @if ($plainTextToken)
        <x-filament::section heading="Copy the new token" description="It is shown only this once. Store it before you leave this page." icon="phosphor-key" data-testid="token-secret">
            <div class="flex items-start gap-2" x-data="{ done: false }">
                <code class="cdn-secret-value min-w-0 flex-1" data-testid="token-secret-value">{{ $plainTextToken }}</code>
                <x-filament::icon-button icon="phosphor-copy" label="Copy token" color="gray" data-testid="token-secret-copy"
                    x-on:click="navigator.clipboard.writeText(@js($plainTextToken)); done = true; setTimeout(() => done = false, 1500)" />
                <span class="sr-only" role="status" x-text="done ? 'Copied' : ''"></span>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Create token" description="Tokens act with your permissions. Give each integration its own token." data-testid="token-create">
        <form wire:submit="createToken" class="cdn-token-form">
            <div class="cdn-token-field cdn-token-field--wide">
                <label class="cdn-field-label" for="token-name">Name</label>
                <x-filament::input.wrapper :valid="! $errors->has('name')">
                    <x-filament::input id="token-name" wire:model="name" placeholder="Deployment automation" maxlength="100" autocomplete="off" data-testid="token-name" />
                </x-filament::input.wrapper>
                @error('name') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="cdn-token-field">
                <label class="cdn-field-label" for="token-ability">Access</label>
                <x-filament::input.wrapper :valid="! $errors->has('ability')">
                    <x-filament::input.select id="token-ability" wire:model="ability" data-testid="token-ability">
                        <option value="*">Full access</option>
                        <option value="read">Read only</option>
                        <option value="dns">DNS records only</option>
                        <option value="purge">Cache purge only</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div class="cdn-token-field">
                <label class="cdn-field-label" for="token-lifetime">Expires after</label>
                <x-filament::input.wrapper :valid="! $errors->has('lifetimeDays')" suffix="days">
                    <x-filament::input id="token-lifetime" type="number" min="1" max="365" placeholder="90" wire:model="lifetimeDays" data-testid="token-lifetime" />
                </x-filament::input.wrapper>
                @error('lifetimeDays') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            @if ($this->domainChoices->isNotEmpty())
                <fieldset class="cdn-token-field cdn-token-field--wide" x-data="{ search: '' }" data-testid="token-domains">
                    <legend class="cdn-field-label">Limit to domains <span class="cdn-token-optional">Optional. Leave empty for every domain you can access.</span></legend>
                    <div class="cdn-token-domains">
                        <x-filament::input.wrapper prefix-icon="phosphor-magnifying-glass">
                            <x-filament::input type="search" x-model="search" placeholder="Filter domains" aria-label="Filter domains" />
                        </x-filament::input.wrapper>
                        <ul class="cdn-token-domain-list">
                            @foreach ($this->domainChoices as $choice)
                                <li x-show="search === '' || @js(strtolower($choice->name)).includes(search.toLowerCase())">
                                    <label class="cdn-token-domain">
                                        <x-filament::input.checkbox wire:model="domainIds" value="{{ $choice->id }}" />
                                        <span class="truncate">{{ $choice->name }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @error('domainIds.*') <p class="cdn-field-error" role="alert">{{ $message }}</p> @enderror
                </fieldset>
            @endif
            <div class="cdn-token-actions">
                <x-filament::button type="submit" icon="phosphor-key" wire:loading.attr="disabled" wire:target="createToken" data-testid="token-create-button">
                    <span wire:loading.remove wire:target="createToken">Create token</span>
                    <span wire:loading wire:target="createToken">Creating…</span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Active tokens">
        <div class="cdn-resource-list">
            @forelse ($this->tokens as $token)
                <x-ui.list-row :title="$token->name" data-testid="token-row">
                    <ul class="cdn-chips mt-1">
                        @if ($token->token_last_six)<li class="cdn-chip">Ends in <span class="cdn-code">{{ $token->token_last_six }}</span></li>@endif
                        <li class="cdn-chip">{{ in_array('*', $token->abilities ?? [], true) ? 'Full access' : collect($token->abilities ?? [])->map(fn (string $ability): string => match ($ability) { 'read' => 'Read only', 'dns' => 'DNS records', 'purge' => 'Cache purge', default => str($ability)->headline()->toString() })->implode(', ') }}</li>
                        @if ($token->domains()->exists())<li class="cdn-chip">{{ trans_choice(':count domain|:count domains', $token->domains()->count()) }}</li>@endif
                        <li class="cdn-chip">Expires {{ $token->expires_at?->diffForHumans() ?? 'never' }}</li>
                        <li class="cdn-chip">Created {{ $token->created_at?->diffForHumans() }}</li>
                    </ul>
                    <x-slot name="aside">
                        <x-filament::button color="danger" outlined size="sm" wire:click="revokeToken({{ $token->id }})" wire:loading.attr="disabled" wire:target="revokeToken({{ $token->id }})" wire:confirm="Revoke this token?">Revoke</x-filament::button>
                    </x-slot>
                </x-ui.list-row>
            @empty
                <x-ui.empty-state icon="phosphor-key" title="No active API tokens" description="Create a named token only when an integration needs API access." />
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
