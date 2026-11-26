<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\IpList;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saving a named IP list of a domain: the limits, the audit entry, and the edge update that only happens when an enabled rule
 * uses the list. The API and the panel share it.
 */
final class DomainIpLists
{
    /**
     * @param  callable(?IpList): array{entries: list<string>, description?: ?string}  $values
     * @return array{0: IpList, 1: ?string} the list and the id of the queued edge operation, if any
     */
    public static function save(Domain $domain, string $name, callable $values, ?User $actor, ?string $ipAddress, string $audit, bool $mustExist = false): array
    {
        $operationId = null;
        $list = DB::transaction(function () use ($domain, $name, $values, $audit, $actor, $ipAddress, $mustExist, &$operationId): IpList {
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $list = $locked->ipLists()->where('name', $name)->first();
            abort_if($mustExist && $list === null, 404);
            abort_if($list === null && $locked->ipLists()->count() >= config('security.ip_lists.maximum_lists_per_domain'), 409, 'The domain already has the maximum number of IP lists.');
            $attributes = $values($list);
            $attributes['entry_count'] = count($attributes['entries']);
            $list = $list === null ? $locked->ipLists()->create(['name' => $name, ...$attributes]) : tap($list)->update($attributes);
            $active = collect(IpListData::rulesUsing($locked, $name))->contains(fn (array $rule): bool => $rule['enabled']);
            if ($active) {
                $locked->update(['revision' => $locked->revision + 1]);
            }
            AuditLog::record($actor, $audit, $locked, ['name' => $name, 'entries' => $list->entry_count, 'revision' => $locked->revision, 'reaches_edge' => $active], $ipAddress);
            $operationId = $active ? DomainSecurityRules::queue($domain, $actor)->id : null;

            return $list;
        });

        return [$list, $operationId];
    }
}
