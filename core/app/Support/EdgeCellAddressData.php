<?php

namespace App\Support;

use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EdgeCellAddressData
{
    /** @return array{service_ipv4:string,service_ipv6?:string|null} */
    public static function validate(EdgeCell $cell, array $input): array
    {
        $edge = $cell->edge()->firstOrFail();
        $input['service_ipv6'] = filled($input['service_ipv6'] ?? null) ? $input['service_ipv6'] : null;
        $data = Validator::make($input, [
            'service_ipv4' => ['required', 'ipv4', Rule::unique('edge_cells', 'service_ipv4')->ignore($cell)],
            'service_ipv6' => [$edge->ipv6 === null ? 'nullable' : 'required', 'nullable', 'ipv6', Rule::unique('edge_cells', 'service_ipv6')->ignore($cell)],
        ])->validate();

        foreach (['service_ipv4', 'service_ipv6'] as $field) {
            $address = $data[$field] ?? null;
            if ($address !== null && NetworkAddress::isUnsafe($address)) {
                throw ValidationException::withMessages([$field => 'The cell service address must be public unicast.']);
            }
        }

        $pool = $cell->pool()->firstOrFail();
        $defaultSharedPoolId = EdgePool::query()->where('kind', 'shared')->orderBy('id')->value('id');
        if ($pool->kind !== 'shared' || $pool->id !== $defaultSharedPoolId) {
            foreach (['service_ipv4', 'service_ipv6'] as $field) {
                $address = $data[$field] ?? null;
                if ($address !== null && Edge::query()->where('ipv4', $address)->orWhere('ipv6', $address)->exists()) {
                    throw ValidationException::withMessages([
                        $field => 'A non-default cell must use a service address distinct from every edge management/default address.',
                    ]);
                }
            }
        }

        return $data;
    }
}
