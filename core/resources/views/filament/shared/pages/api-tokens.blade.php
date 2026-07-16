<x-filament-panels::page>
    @if ($plainTextToken)
        <x-filament::section heading="New token — copy now">
            <code class="break-all select-all">{{ $plainTextToken }}</code>
        </x-filament::section>
    @endif

    <x-filament::section heading="Create token">
        <form wire:submit="createToken" class="flex gap-3">
            <x-filament::input.wrapper class="flex-1">
                <x-filament::input wire:model="name" placeholder="Token name" maxlength="100" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit">Create</x-filament::button>
        </form>
        @error('name') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
    </x-filament::section>

    <x-filament::section heading="Active tokens">
        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($this->tokens as $token)
                <div class="flex items-center justify-between py-3">
                    <div><strong>{{ $token->name }}</strong><div class="text-sm text-gray-500">Created {{ $token->created_at?->diffForHumans() }}</div></div>
                    <x-filament::button color="danger" size="sm" wire:click="revokeToken({{ $token->id }})" wire:confirm="Revoke this token?">Revoke</x-filament::button>
                </div>
            @empty
                <p class="text-sm text-gray-500">No API tokens.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
