<x-filament-panels::page>
    <div class="cdn-settings" x-data="{
            query: '',
            matches: {},
            init() {
                if (location.hash.startsWith('#settings-')) {
                    this.$nextTick(() => document.querySelector(`[data-settings-section='${location.hash.slice(1)}']`)?.scrollIntoView({ block: 'start' }));
                }
            },
            filter() {
                const q = this.query.trim().toLowerCase();
                const matches = {};
                this.$root.querySelectorAll('[data-settings-section]').forEach((section) => {
                    let shown = 0;
                    section.querySelectorAll('[data-testid^=setting-]:not([data-testid^=setting-reset])').forEach((field) => {
                        const row = field.closest('.fi-grid-col') ?? field;
                        const hit = q === '' || row.textContent.toLowerCase().includes(q);
                        row.hidden = ! hit;
                        shown += hit ? 1 : 0;
                    });
                    section.hidden = shown === 0;
                    matches[section.dataset.settingsSection] = shown;
                });
                this.matches = matches;
            },
        }">
        <nav class="cdn-settings-nav" aria-label="Setting categories" data-testid="settings-categories">
            <x-filament::input.wrapper class="cdn-settings-search" prefix-icon="phosphor-magnifying-glass">
                <x-filament::input type="search" x-model.debounce.150ms="query" x-on:input.debounce.150ms="filter()" placeholder="Search settings" aria-label="Search settings" data-testid="settings-search" />
            </x-filament::input.wrapper>
            <ul>
                @foreach ($this->categories as $category)
                    <li x-show="query === '' || (matches['{{ $category['id'] }}'] ?? 0) > 0">
                        <a href="#{{ $category['id'] }}" class="cdn-settings-nav-link" data-testid="settings-category-{{ $category['id'] }}"
                           x-on:click.prevent="document.querySelector('[data-settings-section={{ $category['id'] }}]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); history.replaceState(null, '', '#{{ $category['id'] }}')">
                            <span>{{ $category['label'] }}</span>
                            <span class="cdn-settings-nav-count" x-text="query === '' ? {{ $category['count'] }} : (matches['{{ $category['id'] }}'] ?? 0)">{{ $category['count'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="cdn-settings-empty" x-show="query !== '' && Object.values(matches).every((count) => count === 0)" x-cloak role="status">No setting matches.</p>
        </nav>

        <form wire:submit="save" class="cdn-settings-form" data-testid="settings-form"
              x-data="{ changed: false }"
              x-on:input="if (! $event.target.closest('[data-testid=settings-search]')) changed = true"
              x-on:change="if (! $event.target.closest('[data-testid=settings-search]')) changed = true"
              x-on:click="if ($event.target.closest('[data-testid^=setting-reset]')) changed = true"
              x-on:submit="$nextTick(() => changed = false)">
            {{ $this->form }}
            <div class="cdn-settings-actions">
                <x-filament::button type="submit" x-bind:disabled="! changed" data-testid="settings-save-button">
                    Save changes
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
