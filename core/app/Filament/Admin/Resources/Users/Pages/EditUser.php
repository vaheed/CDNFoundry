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
use Illuminate\Support\Facades\DB;

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
                    $disabling = DB::transaction(function () use ($user): bool {
                        $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
                        abort_if($locked->is(auth()->user()), 422, 'You cannot disable your own account.');
                        $disabling = ! $locked->isDisabled();
                        $locked->forceFill(['disabled_at' => $disabling ? now() : null])->save();
                        if ($disabling) {
                            $locked->tokens()->delete();
                        }
                        AuditLog::record(auth()->user(), $disabling ? 'user.disabled' : 'user.enabled', $locked, [], request()->ip());

                        return $disabling;
                    });
                    Notification::make()->success()->title($disabling ? 'User disabled' : 'User enabled')->send();
                    $this->refreshFormData(['disabled_at']);
                }),
            DeleteAction::make()
                ->disabled(fn (): bool => $this->record->is(auth()->user()) || $this->record->tokens()->exists())
                ->using(function (User $record): bool {
                    return DB::transaction(function () use ($record): bool {
                        $locked = User::query()->lockForUpdate()->findOrFail($record->getKey());
                        abort_if($locked->is(auth()->user()), 422, 'You cannot delete your own account.');
                        abort_if($locked->tokens()->exists(), 409, 'Revoke all tokens before deleting this user.');
                        AuditLog::record(auth()->user(), 'user.deleted', $locked, ['email' => $locked->email], request()->ip());

                        return (bool) $locked->delete();
                    });
                }),
        ];
    }
}
