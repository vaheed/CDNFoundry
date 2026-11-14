{{-- Shown on every domain panel page while an administrator acts as this user. --}}
<div class="cdn-impersonation" role="status" data-testid="impersonation-banner">
    <x-filament::icon icon="phosphor-user-switch" class="size-4" aria-hidden="true" />
    <p>
        Acting as <strong>{{ $user?->name }}</strong> <span class="cdn-impersonation-email">{{ $user?->email }}</span>.
        Changes you make are recorded as theirs, made by {{ $administrator?->name ?? 'an administrator' }}.
    </p>
    <form method="POST" action="{{ route('impersonation.leave') }}">
        @csrf
        <x-filament::button type="submit" size="sm" color="gray" icon="phosphor-arrow-u-up-left" data-testid="impersonation-leave">Return to admin</x-filament::button>
    </form>
</div>
