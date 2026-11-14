<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Filament\Domain\Resources\Domains\RelationManagers\Concerns\SyncsDomainPage;
use App\Jobs\TestLogPushDestination;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\LogPushDestination;
use App\Models\Operation;
use App\Support\FilamentHelp;
use App\Support\LogPush\DestinationData;
use App\Support\LogPush\LogDatasets;
use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/** Where this domain's own logs are pushed, with the state of each destination. The tab exists while the administrator switch is on or a destination is kept. */
class LogPushRelationManager extends RelationManager
{
    use SyncsDomainPage;

    protected static string $relationship = 'logPushDestinations';

    protected static ?string $title = 'Log push';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord->logPushDestinations()->count());
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return self::enabled() || $ownerRecord->logPushDestinations()->exists();
    }

    private static function enabled(): bool
    {
        return app(PlatformSettings::class)->get('log_push', 'domain_user_log_push') === true;
    }

    public function table(Table $table): Table
    {
        $columns = TextColumn::class;

        return $table->columns([
            $columns::make('name')->limit(40)->tooltip(fn ($record): ?string => mb_strlen($record->name) > 40 ? $record->name : null),
            $columns::make('destination_hint')->label('Destination')->limit(28)->tooltip(fn ($record): ?string => mb_strlen($record->destination_hint) > 28 ? $record->destination_hint : null),
            $columns::make('datasets')->label('Logs')->badge()->color('gray')->formatStateUsing(fn (string $state): string => LogDatasets::ALL[$state] ?? $state),
            $columns::make('status')->badge()->formatStateUsing(fn (string $state, $record): string => $record->consecutive_failures > 0 ? 'Failing' : ucfirst($state))
                ->color(fn (string $state, $record): string => $record->consecutive_failures > 0 ? 'danger' : ($state === 'active' ? 'success' : 'gray')),
            $columns::make('last_delivery_at')->label(FilamentHelp::label('Last delivery', 'When a batch last reached the destination. Nothing is sent while the domain has no new log rows.'))->since()->placeholder('Not yet'),
            $columns::make('lag_seconds')->label(FilamentHelp::label('Lag', 'How old the newest delivered row was when it was sent. A destination that keeps failing falls further behind.'))
                ->formatStateUsing(fn ($state): string => $state === null ? 'None' : Number::format((int) $state).' s')->alignEnd(),
            $columns::make('consecutive_failures')->label(FilamentHelp::label('Failures', 'Deliveries that failed in a row. It returns to 0 after a success. The last error appears on hover.'))
                ->tooltip(fn ($record): ?string => $record->last_error)->alignEnd(),
            $columns::make('bytes_pushed')->label(FilamentHelp::label('Sent', 'Compressed bytes delivered so far.'))->formatStateUsing(fn ($state): string => Number::fileSize((int) $state))->alignEnd()->toggleable(isToggledHiddenByDefault: true),
        ])->headerActions([
            Action::make('addDestination')->label('Add destination')->icon('phosphor-plus')->visible(fn (): bool => self::enabled())->modalWidth('lg')
                ->modalDescription('Rows of this domain only. Delivery starts with the next new row.')
                ->schema([
                    TextInput::make('name')->label('Name')->placeholder('Security team SIEM')->required()->maxLength(100),
                    Select::make('kind')->label(FilamentHelp::label('Type', 'HTTPS posts compressed JSON lines to your endpoint. S3 writes one object per batch to an S3-compatible bucket.'))
                        ->options(['https' => 'HTTPS endpoint', 's3' => 'S3-compatible storage'])->default('https')->live()->required(),
                    CheckboxList::make('datasets')->label(FilamentHelp::label('Logs', 'HTTP requests are every request served for the domain. Security events are the requests the platform blocked or flagged. DNS queries are questions answered for your zone.'))
                        ->options(LogDatasets::ALL)->default([LogDatasets::HTTP_REQUESTS])->required(),
                    TextInput::make('url')->label('Endpoint URL')->placeholder('https://logs.example.com/ingest')->url()->visible(fn (Get $get): bool => $get('kind') === 'https')->required(fn (Get $get): bool => $get('kind') === 'https'),
                    Select::make('auth')->label(FilamentHelp::label('Authentication', 'Bearer sends your token in the Authorization header. Signature adds an X-CDNFoundry-Signature header (HMAC-SHA256 of the body) and shows you the signing secret once.'))
                        ->options(['hmac' => 'Signature (HMAC)', 'bearer' => 'Bearer token'])->default('hmac')->live()->visible(fn (Get $get): bool => $get('kind') === 'https'),
                    TextInput::make('bearer_token')->label('Bearer token')->password()->revealable()->placeholder('d1f2a3b4c5d6e7f8')->visible(fn (Get $get): bool => $get('kind') === 'https' && $get('auth') === 'bearer')->required(fn (Get $get): bool => $get('kind') === 'https' && $get('auth') === 'bearer'),
                    TextInput::make('endpoint')->label('Endpoint')->placeholder('https://s3.eu-west-1.amazonaws.com')->url()->visible(fn (Get $get): bool => $get('kind') === 's3')->required(fn (Get $get): bool => $get('kind') === 's3'),
                    TextInput::make('bucket')->label('Bucket')->placeholder('cdn-logs')->visible(fn (Get $get): bool => $get('kind') === 's3')->required(fn (Get $get): bool => $get('kind') === 's3'),
                    TextInput::make('region')->label('Region')->placeholder('eu-west-1')->visible(fn (Get $get): bool => $get('kind') === 's3')->required(fn (Get $get): bool => $get('kind') === 's3'),
                    TextInput::make('prefix')->label(FilamentHelp::label('Folder', 'Optional path inside the bucket. Objects are written under folder/domain/log/date.'))->placeholder('cdnfoundry')->visible(fn (Get $get): bool => $get('kind') === 's3'),
                    TextInput::make('access_key')->label('Access key')->placeholder('AKIAIOSFODNN7EXAMPLE')->visible(fn (Get $get): bool => $get('kind') === 's3')->required(fn (Get $get): bool => $get('kind') === 's3'),
                    TextInput::make('secret_key')->label('Secret key')->password()->revealable()->placeholder('wJalrXUtnFEMI/K7MDENG')->visible(fn (Get $get): bool => $get('kind') === 's3')->required(fn (Get $get): bool => $get('kind') === 's3'),
                    Toggle::make('truncate_client_ip')->label(FilamentHelp::label('Truncate client addresses', 'Sends 203.0.113.0 instead of 203.0.113.77, and the first 48 bits of an IPv6 address. Use it when your receiver should not hold full addresses.')),
                ])->action(fn (array $data) => $this->create($data)),
        ])->recordActions([
            Action::make('test')->label('Send test')->icon('phosphor-paper-plane-tilt')->visible(fn (): bool => self::enabled())->action(fn (LogPushDestination $record) => $this->test($record)),
            Action::make('toggle')->label(fn (LogPushDestination $record): string => $record->status === 'paused' ? 'Resume' : 'Pause')->icon('phosphor-pause')
                ->action(fn (LogPushDestination $record) => $this->setStatus($record, $record->status === 'paused' ? 'active' : 'paused')),
            DeleteAction::make()->using(fn (LogPushDestination $record): bool => $this->remove($record)),
        ])->emptyStateHeading('No log push destinations')->emptyStateDescription('Add a destination to receive this domain\'s logs at an endpoint you control.')->paginated(false);
    }

    private function create(array $data): void
    {
        try {
            $validated = DestinationData::validate($data);
            $domain = $this->getOwnerRecord();
            DB::transaction(function () use ($domain, $validated): void {
                Domain::query()->lockForUpdate()->findOrFail($domain->id);
                DestinationData::assertRoom($domain);
                $destination = new LogPushDestination(['domain_id' => $domain->id, 'kind' => $validated['kind'], 'name' => $validated['name'], 'datasets' => $validated['datasets'], 'destination_hint' => $validated['hint'],
                    'truncate_client_ip' => $validated['truncate'], 'status' => 'active', 'cursors' => [], 'consecutive_failures' => 0, 'failures_total' => 0, 'rows_pushed' => 0, 'bytes_pushed' => 0]);
                $destination->storeSecrets($validated['secrets']);
                $destination->save();
                AuditLog::record(auth()->user(), 'log_push.created', $domain, ['destination_id' => $destination->id, 'kind' => $destination->kind, 'datasets' => $destination->datasets], request()->ip());
            });
        } catch (ValidationException $exception) {
            // The modal's fields live under the action's state path, so a server-side refusal appears beside the field that caused it.
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["mountedActions.0.data.{$key}" => $messages])->all());
        }
        $notice = Notification::make()->success()->title('Destination added');
        if ($validated['signing_secret'] !== null) {
            $notice->persistent()->body('Signing secret, shown only once: '.$validated['signing_secret']);
        }
        $notice->send();
    }

    private function test(LogPushDestination $record): void
    {
        try {
            $operation = Operation::query()->create(['actor_id' => auth()->id(), 'domain_id' => $record->domain_id, 'type' => 'log_push.test', 'status' => 'pending', 'input' => ['domain_id' => $record->domain_id, 'destination_id' => $record->id]]);
        } catch (UniqueConstraintViolationException) {
            Notification::make()->warning()->title('A test is already running for this domain')->send();

            return;
        }
        TestLogPushDestination::dispatch($operation->id);
        Notification::make()->success()->title('Test message queued')->body("Operation {$operation->id}.")->send();
    }

    private function setStatus(LogPushDestination $record, string $status): void
    {
        $record->forceFill(['status' => $status, 'next_run_at' => null])->save();
        AuditLog::record(auth()->user(), 'log_push.'.($status === 'paused' ? 'paused' : 'resumed'), $this->getOwnerRecord(), ['destination_id' => $record->id], request()->ip());
    }

    private function remove(LogPushDestination $record): bool
    {
        AuditLog::record(auth()->user(), 'log_push.deleted', $this->getOwnerRecord(), ['destination_id' => $record->id], request()->ip());

        return (bool) $record->delete();
    }
}
