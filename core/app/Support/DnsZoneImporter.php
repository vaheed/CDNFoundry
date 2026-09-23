<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DnsZoneImporter
{
    /** @param list<array<string,mixed>> $records */
    public static function apply(int $domainId, array $records, bool $replaceExisting, ?User $actor, ?string $ipAddress): array
    {
        if ($records === []) {
            throw ValidationException::withMessages(['zone' => 'The zone contains no supported records.']);
        }

        return DB::transaction(function () use ($domainId, $records, $replaceExisting, $actor, $ipAddress): array {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domainId);
            $actor = $actor === null ? null : User::query()->lockForUpdate()->find($actor->id);
            abort_unless($actor !== null && ! $actor->isDisabled(), 403, 'The import actor is no longer authorized.');
            Gate::forUser($actor)->authorize('update', $domain);
            if (! $actor->isAdmin()) {
                abort_unless(DB::table('domain_user')->where('domain_id', $domainId)->where('user_id', $actor->id)
                    ->lockForUpdate()->first() !== null, 403, 'The import assignment has been revoked.');
                if (collect($records)->contains(fn (array $record): bool => $record['type'] === 'NS')) {
                    abort(403, 'Only administrators can manage delegated NS records.');
                }
            }
            $existing = $domain->dnsRecords()->lockForUpdate()->get();
            if ($replaceExisting && ! $actor->isAdmin() && $existing->contains(fn (DnsRecord $record): bool => $record->type === 'NS')) {
                abort(403, 'Only administrators can manage delegated NS records.');
            }
            $final = collect($records);
            if (! $replaceExisting) {
                $final = $existing->map(fn (DnsRecord $record): array => $record->only(['type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode']))->concat($final);
            }
            DnsZoneValidator::assertValid($final, $domain->name);
            if ($replaceExisting) {
                $domain->dnsRecords()->delete();
            }
            foreach ($records as $record) {
                $domain->dnsRecords()->create($record);
            }
            $domain->forceFill(['revision' => $domain->revision + 1])->save();
            AuditLog::record($actor, 'dns.zone_imported', $domain, [
                'revision' => $domain->revision, 'records' => count($records), 'replaced' => $replaceExisting,
            ], $ipAddress);

            return ['domain_id' => $domain->id, 'revision' => $domain->revision, 'imported' => count($records), 'replaced' => $replaceExisting];
        });
    }
}
