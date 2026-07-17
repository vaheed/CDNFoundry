<?php

namespace App\Filament\Admin\Resources\Operations;

use App\Filament\Admin\Resources\Operations\Pages\ListOperations;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Jobs\DispatchOriginTest;
use App\Jobs\ImportDnsZone;
use App\Jobs\ReconcileAllDnsZones;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\TestDnsCluster;
use App\Jobs\VerifyDomainNameservers;
use App\Models\AuditLog;
use App\Models\Operation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OperationResource extends Resource
{
    private const TYPES = [
        'platform_dns_identity.update' => 'Platform DNS identity update',
        'domain.nameservers_verify' => 'Domain nameserver verification',
        'dns.zone_reconcile' => 'DNS zone reconciliation',
        'dns.zone_import' => 'DNS zone import',
        'dns.cluster_test' => 'DNS cluster test',
        'dns.global_reconcile' => 'Global DNS reconciliation',
        'edge.domain_reconcile' => 'Edge domain deployment',
        'edge.origin_test' => 'Edge origin test',
        'edge.global_reconcile' => 'Global edge reconciliation',
    ];

    protected static ?string $model = Operation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Operation ID')->copyable()->searchable()->limit(12)->tooltip(fn (Operation $record): string => $record->id),
                TextColumn::make('type')->formatStateUsing(fn (string $state): string => self::TYPES[$state] ?? $state)->description(fn (Operation $record): string => $record->type)->searchable()->sortable()->wrap(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('actor.email')->label('Requested by')->placeholder('System')->searchable()->sortable()->toggleable(),
                TextColumn::make('attempts')->numeric()->sortable(),
                TextColumn::make('error')->searchable()->limit(80)->tooltip(fn (Operation $record): ?string => $record->error)->wrap()->placeholder('—'),
                TextColumn::make('created_at')->label('Requested')->dateTime()->since()->sortable(),
                TextColumn::make('started_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('finished_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('duration')->state(function (Operation $record): string {
                    if ($record->started_at === null) {
                        return '—';
                    }

                    return $record->started_at->diffForHumans($record->finished_at ?? now(), true);
                })->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->multiple()->options([
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'succeeded' => 'Succeeded',
                    'failed' => 'Failed',
                ]),
                SelectFilter::make('type')->multiple()->options(self::TYPES),
                SelectFilter::make('actor')->relationship('actor', 'email')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->visible(fn (Operation $record): bool => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->action(function (Operation $record): void {
                        abort_unless(array_key_exists($record->type, self::TYPES), 422, 'Unsupported operation type.');
                        $record->update(['status' => 'pending', 'error' => null, 'finished_at' => null]);
                        AuditLog::record(auth()->user(), 'operation.retry_requested', $record, [], request()->ip());
                        match ($record->type) {
                            'platform_dns_identity.update' => ApplyPlatformDnsSettings::dispatch($record->getKey()),
                            'domain.nameservers_verify' => VerifyDomainNameservers::dispatch((int) $record->input['domain_id']),
                            'dns.zone_reconcile' => ReconcileDnsZone::dispatch((int) $record->input['domain_id']),
                            'dns.zone_import' => ImportDnsZone::dispatch($record->getKey()),
                            'dns.cluster_test' => TestDnsCluster::dispatch($record->getKey()),
                            'dns.global_reconcile' => ReconcileAllDnsZones::dispatch($record->getKey()),
                            'edge.domain_reconcile' => ReconcileEdgeDomain::dispatch((int) $record->input['domain_id']),
                            'edge.origin_test' => DispatchOriginTest::dispatch($record->getKey()),
                        };
                        Notification::make()->success()->title('Operation queued for retry')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListOperations::route('/')];
    }
}
