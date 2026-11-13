<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    public function getSubheading(): ?string
    {
        return 'Zones and their delivery state.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createDomain')->label('Add domain')->icon('phosphor-plus')->slideOver()->modalWidth(Width::TwoExtraLarge)
                ->modalHeading('Add domain')->modalDescription('Enter the domain to serve. No origin is required for DNS-only use.')
                ->modalSubmitActionLabel('Add domain')
                ->extraAttributes(['data-testid' => 'domain-create-button'])
                ->schema([TextInput::make('name')->label('Domain')->placeholder('example.com')->required()->maxLength(253)])
                ->action(function (array $data) {
                    try {
                        $domain = Domain::createPendingFor(auth()->user(), (string) $data['name'], request()->ip());
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(['mountedActions.0.data.name' => $exception->errors()['name'] ?? ['The domain could not be created.']]);
                    }

                    return redirect(DomainResource::getUrl('view', ['record' => $domain]));
                }),
        ];
    }
}
