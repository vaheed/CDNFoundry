<?php

namespace App\Filament\Admin\Resources\Edges\Pages;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class ViewEdge extends ViewRecord
{
    protected static string $resource = EdgeResource::class;

    #[Locked]
    public ?string $bootstrapToken = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $enrollment = session()->pull('edge_enrollment');
        if (! is_array($enrollment) || ! hash_equals((string) ($enrollment['edge_id'] ?? ''), (string) $this->getRecord()->getKey())) {
            return;
        }

        $this->bootstrapToken = (string) ($enrollment['bootstrap_token'] ?? '');
        if ($this->bootstrapToken !== '') {
            $this->defaultAction = 'showEnrollment';
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('showEnrollment')
                ->label('Enrollment instructions')
                ->visible(fn (): bool => filled($this->bootstrapToken))
                ->modalHeading('Save the one-time edge enrollment details')
                ->modalDescription('The bootstrap token cannot be displayed again after this modal is acknowledged or the page is left.')
                ->modalContent(function () {
                    $nodeName = (string) $this->getRecord()->name;
                    $tokenFileName = (Str::slug($nodeName) ?: 'edge').'.bootstrap-token';
                    $tokenPath = "/root/{$tokenFileName}";

                    return view('filament.admin.resources.edges.enrollment-instructions', [
                        'edgeId' => (string) $this->getRecord()->getKey(),
                        'bootstrapToken' => $this->bootstrapToken,
                        'nodeName' => $nodeName,
                        'shellNodeName' => escapeshellarg($nodeName),
                        'tokenPath' => $tokenPath,
                    ]);
                })
                ->modalWidth('5xl')
                ->closeModalByClickingAway(false)
                ->closeModalByEscaping(false)
                ->modalCloseButton(false)
                ->modalCancelAction(false)
                ->modalSubmitActionLabel('I saved these one-time details')
                ->action(function (): void {
                    $this->bootstrapToken = null;
                    $this->defaultAction = null;
                }),
            EditAction::make(),
        ];
    }
}
