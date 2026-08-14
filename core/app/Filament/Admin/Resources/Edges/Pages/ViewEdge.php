<?php

namespace App\Filament\Admin\Resources\Edges\Pages;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Edge;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
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
                ->modalHeading('Enroll this edge')
                ->modalDescription('Copy the two environment values to the prepared edge host and run its start script. The token is shown only once.')
                ->modalContent(function () {
                    return view('filament.admin.resources.edges.enrollment-instructions', [
                        'edgeId' => (string) $this->getRecord()->getKey(),
                        'bootstrapToken' => $this->bootstrapToken,
                        'edgeName' => (string) $this->getRecord()->name,
                        'flow' => 'enrollment',
                    ]);
                })
                ->modalWidth('6xl')
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->closeModalByClickingAway(false)
                ->closeModalByEscaping(false)
                ->modalCloseButton(false)
                ->modalCancelAction(false)
                ->schema([
                    Checkbox::make('saved')
                        ->label('I securely saved the UUID and one-time token, and I understand this modal cannot be reopened.')
                        ->accepted()
                        ->required(),
                ])
                ->modalSubmitActionLabel('Finish enrollment setup')
                ->action(function (array $data): void {
                    $this->bootstrapToken = null;
                    $this->defaultAction = null;
                }),
            $this->rotateIdentityAction(),
            EditAction::make(),
        ];
    }

    private function rotateIdentityAction(): Action
    {
        return Action::make('rotateIdentity')
            ->label('Rotate identity')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->modalHeading('Rotate this edge identity?')
            ->modalDescription('The current mTLS certificate is revoked immediately. Existing runtime traffic can continue with its last valid configuration, but heartbeats and configuration updates stop until the agent enrolls again.')
            ->schema([
                Checkbox::make('confirm_rotation')
                    ->label('I am ready to paste the replacement values on the edge host and run its start script immediately after rotation.')
                    ->accepted()
                    ->required(),
            ])
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->label('Revoke identity and issue token')
                ->color('danger'))
            ->action(function (Edge $record, HasActions $livewire): void {
                $token = Str::random(64);

                DB::transaction(function () use ($record, $token): void {
                    $record->update([
                        'identity_hash' => null,
                        'identity_csr_hash' => null,
                        'identity_certificate' => null,
                        'identity_certificate_serial' => null,
                        'identity_certificate_expires_at' => null,
                        'identity_revoked_at' => now(),
                        'bootstrap_token_hash' => hash('sha256', $token),
                        'bootstrap_consumed_at' => null,
                        'registered_at' => null,
                    ]);
                    AuditLog::record(auth()->user(), 'edge.identity_rotated', $record, [], request()->ip());
                    ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
                });

                $livewire->mountAction('showRotatedIdentity', arguments: [
                    'edgeId' => (string) $record->getKey(),
                    'bootstrapToken' => $token,
                    'nodeName' => (string) $record->name,
                ]);
            })
            ->registerModalActions([
                Action::make('showRotatedIdentity')
                    ->modalHeading('Identity rotated — re-enroll this edge now')
                    ->modalDescription('The old certificate is revoked. Complete these steps before leaving this modal; the replacement token is shown only once.')
                    ->modalContent(function (array $arguments) {
                        return view('filament.admin.resources.edges.enrollment-instructions', [
                            'edgeId' => (string) $arguments['edgeId'],
                            'bootstrapToken' => (string) $arguments['bootstrapToken'],
                            'edgeName' => (string) $arguments['nodeName'],
                            'flow' => 'rotation',
                        ]);
                    })
                    ->modalWidth('6xl')
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->closeModalByClickingAway(false)
                    ->closeModalByEscaping(false)
                    ->modalCloseButton(false)
                    ->modalCancelAction(false)
                    ->schema([
                        Checkbox::make('saved')
                            ->label('I securely saved the replacement token and understand the old identity is already revoked.')
                            ->accepted()
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('I saved the recovery instructions')
                    ->cancelParentActions(),
            ]);
    }
}
