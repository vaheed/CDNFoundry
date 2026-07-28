<?php

namespace App\Support;

use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use Illuminate\Support\Facades\Validator;

final class EdgePoolEndpointData
{
    public static function validate(array $input, ?EdgePoolEndpoint $endpoint = null, ?EdgePool $pool = null): array
    {
        $input['ipv4'] = filled($input['ipv4'] ?? null) ? $input['ipv4'] : null;
        $input['ipv6'] = filled($input['ipv6'] ?? null) ? $input['ipv6'] : null;
        $data = Validator::make($input, [
            'ipv4' => ['nullable', 'ipv4'],
            'ipv6' => ['nullable', 'ipv6'],
            'withdrawn' => ['sometimes', 'boolean'],
        ])->after(function ($validator) use ($input, $endpoint, $pool): void {
            $pool ??= $endpoint?->pool;
            if ($pool?->isSimpleAnycast() && (filled($input['ipv4'] ?? null) || filled($input['ipv6'] ?? null))) {
                $validator->errors()->add('ipv4', 'Simple Anycast endpoints inherit the pool-level service pair.');
            }
            if (! $pool?->isSimpleAnycast() && ! filled($input['ipv4'] ?? null) && ! filled($input['ipv6'] ?? null)) {
                $validator->errors()->add('ipv4', 'At least one service address is required.');
            }
            foreach (['ipv4', 'ipv6'] as $field) {
                $address = $input[$field] ?? null;
                if ($address && (NetworkAddress::isUnsafe($address) || Edge::query()->where('management_ipv4', $address)->orWhere('management_ipv6', $address)->exists())) {
                    $validator->errors()->add($field, 'The endpoint must use a public unicast address distinct from management addresses.');
                }
                if ($address && EdgePoolEndpoint::query()->where($field, $address)->when($endpoint, fn ($query) => $query->whereKeyNot($endpoint->id))->exists()) {
                    $validator->errors()->add($field, 'The service address is already assigned to another endpoint.');
                }
                if ($address && EdgePool::query()->where('anycast_'.$field, $address)->exists()) {
                    $validator->errors()->add($field, 'The service address is already assigned to an Anycast pool.');
                }
            }
        })->validate();

        return $data;
    }
}
