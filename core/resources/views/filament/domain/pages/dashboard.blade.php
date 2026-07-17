<x-filament-panels::page>
    <x-filament::section heading="Authoritative DNS">
        <p class="text-gray-600 dark:text-gray-300">Add a domain without an origin, configure the required nameservers, then manage its authoritative DNS records.</p>
        <div class="mt-4">
            <x-filament::button tag="a" :href="\App\Filament\Domain\Resources\Domains\DomainResource::getUrl()">Manage domains</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
