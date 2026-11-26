<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\IpList;
use App\Support\DomainIpLists;
use App\Support\IpListData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Named IP lists of a domain. A change reaches the cells only when an enabled rule uses the list: then it is one revision and one
 * queued edge update like any rule change. A list no rule uses is stored without touching the edge.
 */
class IpListController extends Controller
{
    public function index(Domain $domain): JsonResponse
    {
        Gate::authorize('view', $domain);
        $lists = $domain->ipLists()->orderBy('name')->get(['id', 'name', 'description', 'entry_count', 'updated_at']);

        return response()->json(['data' => $lists->map(fn (IpList $list): array => [...$list->only(['name', 'description', 'entry_count', 'updated_at']), 'rules_using' => count(IpListData::rulesUsing($domain, $list->name))])->all(),
            'limits' => config('security.ip_lists')]);
    }

    public function show(Domain $domain, string $name): JsonResponse
    {
        Gate::authorize('view', $domain);
        $list = $domain->ipLists()->where('name', $name)->firstOrFail();

        return response()->json(['data' => [...$list->only(['name', 'description', 'entry_count', 'updated_at']), 'entries' => $list->entries, 'rules_using' => count(IpListData::rulesUsing($domain, $name))]]);
    }

    /** Creates the list or replaces its entries. */
    public function replace(Request $request, Domain $domain, string $name): JsonResponse
    {
        Gate::authorize('update', $domain);
        $this->assertName($name);
        $data = $request->validate(['description' => ['nullable', 'string', 'max:250'], 'entries' => ['present', 'array']]);
        $entries = IpListData::normalize($data['entries']);

        return $this->save($request, $domain, $name, fn (?IpList $list): array => ['entries' => $entries, 'description' => array_key_exists('description', $data) ? $data['description'] : $list?->description], 'ip_list.saved');
    }

    /** Adds and removes entries without sending the whole list. */
    public function change(Request $request, Domain $domain, string $name): JsonResponse
    {
        Gate::authorize('update', $domain);
        $limit = (int) config('security.ip_lists.maximum_change_entries');
        $data = $request->validate(['add' => ['sometimes', 'array', 'max:'.$limit], 'remove' => ['sometimes', 'array', 'max:'.$limit]]);
        $domain->ipLists()->where('name', $name)->firstOrFail();
        $add = IpListData::normalize($data['add'] ?? [], 'add');
        $remove = IpListData::normalize($data['remove'] ?? [], 'remove');

        return $this->save($request, $domain, $name, function (IpList $list) use ($add, $remove): array {
            $entries = array_values(array_diff([...$list->entries, ...$add], $remove));
            $entries = IpListData::normalize($entries);

            return ['entries' => $entries];
        }, 'ip_list.changed', mustExist: true);
    }

    public function destroy(Request $request, Domain $domain, string $name): JsonResponse
    {
        Gate::authorize('update', $domain);
        $list = $domain->ipLists()->where('name', $name)->firstOrFail();
        $using = IpListData::rulesUsing($domain, $name);
        abort_if($using !== [], 409, 'The list is used by '.count($using).' firewall '.str('rule')->plural(count($using)).'. Delete or change '.(count($using) === 1 ? 'it' : 'them').' first.');
        DB::transaction(function () use ($domain, $list, $request): void {
            AuditLog::record($request->user(), 'ip_list.deleted', $domain, ['name' => $list->name, 'entries' => $list->entry_count], $request->ip());
            $list->delete();
        });

        return response()->json(null, 204);
    }

    private function assertName(string $name): void
    {
        if (! IpListData::validName($name)) {
            throw ValidationException::withMessages(['name' => 'The name must be lower case letters, digits, hyphens and underscores, up to 40 characters.']);
        }
    }

    /** @param  callable(?IpList): array{entries: list<string>, description?: ?string}  $values */
    private function save(Request $request, Domain $domain, string $name, callable $values, string $audit, bool $mustExist = false): JsonResponse
    {
        [$list, $operationId] = DomainIpLists::save($domain, $name, $values, $request->user(), $request->ip(), $audit, $mustExist);

        return response()->json(['data' => [...$list->only(['name', 'description', 'entry_count']), 'operation_id' => $operationId]], $operationId === null ? 200 : 202);
    }
}
