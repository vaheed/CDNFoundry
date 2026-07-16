<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_if($record->is(auth()->user()) && ($data['type'] ?? null) === 'user', 422, 'You cannot remove your own administrator access.');
        $record->update($data);
        AuditLog::record(auth()->user(), 'user.updated', $record, ['fields' => array_keys($data)], request()->ip());

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleAccess')
                ->label(fn (): string => $this->record->isDisabled() ? 'Enable access' : 'Disable access')
                ->color(fn (): string => $this->record->isDisabled() ? 'success' : 'warning')
                ->disabled(fn (): bool => $this->record->is(auth()->user()))
                ->action(function (): void {
                    /** @var User $user */
                    $user = $this->record;
                    $disabling = ! $user->isDisabled();
                    $user->forceFill(['disabled_at' => $disabling ? now() : null])->save();
                    if ($disabling) {
                        $user->tokens()->delete();
                    }
                    AuditLog::record(auth()->user(), $disabling ? 'user.disabled' : 'user.enabled', $user, [], request()->ip());
                    Notification::make()->success()->title($disabling ? 'User disabled' : 'User enabled')->send();
                    $this->refreshFormData(['disabled_at']);
                }),
            DeleteAction::make()
                ->disabled(fn (): bool => $this->record->is(auth()->user()) || $this->record->tokens()->exists())
                ->before(fn () => AuditLog::record(auth()->user(), 'user.deleted', $this->record, ['email' => $this->record->email], request()->ip())),
        ];
    }
}
