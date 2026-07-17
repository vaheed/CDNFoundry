<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DnsZoneImporter
{
    /** @param list<array<string,mixed>> $records */
    public static function apply(int $domainId, array $records, bool $replaceExisting, ?User $actor, ?string $ipAddress): array
    {
        if ($records === []) {
            throw ValidationException::withMessages(['zone' => 'The zone contains no supported records.']);
        }
        if ($actor?->isAdmin() !== true && collect($records)->contains(fn (array $record): bool => $record['type'] === 'NS')) {
            abort(403, 'Only administrators can manage delegated NS records.');
        }

        return DB::transaction(function () use ($domainId, $records, $replaceExisting, $actor, $ipAddress): array {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domainId);
            $existing = $domain->dnsRecords()->lockForUpdate()->get();
            if ($replaceExisting && $actor?->isAdmin() !== true && $existing->contains(fn (DnsRecord $record): bool => $record->type === 'NS')) {
                abort(403, 'Only administrators can manage delegated NS records.');
            }
            $final = collect($records);
            if (! $replaceExisting) {
                $final = $existing->map(fn (DnsRecord $record): array => $record->only(['type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode']))->concat($final);
            }
            DnsZoneValidator::assertValid($final);
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
