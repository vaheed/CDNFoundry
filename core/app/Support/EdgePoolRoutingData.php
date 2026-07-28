<?php

namespace App\Support;

use App\Models\Edge;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class EdgePoolRoutingData
{
    public static function validate(array $input, ?EdgePool $pool = null, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';
        $data = Validator::make($input, [
            'routing_mode' => [$sometimes, Rule::in(['geo_unicast', 'simple_anycast'])],
            'anycast_ipv4' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'ipv4', Rule::unique('edge_pools', 'anycast_ipv4')->ignore($pool)],
            'anycast_ipv6' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'ipv6', Rule::unique('edge_pools', 'anycast_ipv6')->ignore($pool)],
        ])->after(function ($validator) use ($input, $pool): void {
            $mode = $input['routing_mode'] ?? $pool?->routing_mode ?? 'geo_unicast';
            $ipv4 = array_key_exists('anycast_ipv4', $input) ? $input['anycast_ipv4'] : $pool?->anycast_ipv4;
            $ipv6 = array_key_exists('anycast_ipv6', $input) ? $input['anycast_ipv6'] : $pool?->anycast_ipv6;
            if ($mode === 'simple_anycast' && ! filled($ipv4) && ! filled($ipv6)) {
                $validator->errors()->add('anycast_ipv4', 'Simple Anycast requires at least one pool-level service address.');
            }
            if ($mode === 'geo_unicast' && (filled($ipv4) || filled($ipv6))) {
                $validator->errors()->add('anycast_ipv4', 'Geo-Unicast pools cannot store an Anycast service pair.');
            }
            foreach (['ipv4' => $ipv4, 'ipv6' => $ipv6] as $family => $address) {
                if (! $address) {
                    continue;
                }
                if (NetworkAddress::isUnsafe($address) || Edge::query()->where('management_ipv4', $address)->orWhere('management_ipv6', $address)->exists()) {
                    $validator->errors()->add('anycast_'.$family, 'The Anycast endpoint must use public unicast space distinct from management addresses.');
                }
                if (EdgePoolEndpoint::query()->where($family, $address)->exists()) {
                    $validator->errors()->add('anycast_'.$family, 'The service address is already assigned to a Geo-Unicast endpoint.');
                }
            }
        })->validate();

        return $data;
    }
}
