<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Models\AuditLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** What happened to a domain: operator actions that changed it, and changes the platform made on its own. */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip('Y-m-d H:i:s')->sortable(),
            TextColumn::make('actor.name')->label('By')->placeholder('Platform')->badge()
                ->color(fn (?string $state): string => $state === null ? 'gray' : 'info'),
            TextColumn::make('action')->label('Event')->formatStateUsing(fn (string $state): string => self::label($state)),
            TextColumn::make('metadata')->label('Detail')->state(fn (AuditLog $record): ?string => self::detail($record))->limit(80)
                ->tooltip(fn (AuditLog $record): ?string => self::detail($record)),
        ])->filters([
            SelectFilter::make('source')->label('Source')->options(['operator' => 'Operator actions', 'automatic' => 'Automatic changes'])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'operator' => $query->whereNotNull('actor_id'),
                    'automatic' => $query->whereNull('actor_id'),
                    default => $query,
                }),
        ])->deferFilters(false)->defaultSort('created_at', 'desc')->defaultPaginationPageOption(10)->paginated([10, 25, 50]);
    }

    private static function label(string $action): string
    {
        return match ($action) {
            'security.automatic_state_changed' => 'Security state changed',
            'security.state_recovered' => 'Security state recovered',
            default => ucfirst(str_replace(['.', '_'], ' ', $action)),
        };
    }

    private static function detail(AuditLog $record): ?string
    {
        $metadata = (array) $record->metadata;
        if ($record->action === 'security.automatic_state_changed') {
            $cause = match ($metadata['cause'] ?? null) {
                'event_rate' => ($metadata['events_in_window'] ?? '?').' events in '.($metadata['window_minutes'] ?? '?').' minutes',
                'quiet_period' => 'quiet for '.($metadata['quiet_minutes'] ?? '?').' minutes',
                'operator_return_to_normal' => 'returned to normal by an operator',
                default => null,
            };

            return ucfirst((string) ($metadata['from'] ?? '?')).' to '.($metadata['to'] ?? '?').($cause === null ? '' : ', '.$cause);
        }
        if (isset($metadata['revision'])) {
            return 'Revision #'.$metadata['revision'];
        }

        return null;
    }
}
