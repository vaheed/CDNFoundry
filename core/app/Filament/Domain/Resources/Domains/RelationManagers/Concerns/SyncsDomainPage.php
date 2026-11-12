<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers\Concerns;

use Livewire\Attributes\On;

/** Keeps a domain tab and the domain page in step: tab actions refresh the page, page actions refresh the tab. */
trait SyncsDomainPage
{
    public function callMountedAction(array $arguments = []): mixed
    {
        $result = parent::callMountedAction($arguments);
        $this->dispatch('domain-updated');

        return $result;
    }

    #[On('domain-refreshed')]
    public function refreshFromDomainPage(bool $tabs = false): void
    {
        if (! $tabs) {
            $this->skipRender();
        }
    }
}
