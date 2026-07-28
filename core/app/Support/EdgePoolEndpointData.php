<?php

namespace App\Support;

use App\Models\Edge;
use App\Models\EdgePoolEndpoint;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class EdgePoolEndpointData
{
    public static function validate(array $input, ?EdgePoolEndpoint $endpoint = null): array
    {
        $input['ipv4'] = filled($input['ipv4'] ?? null) ? $input['ipv4'] : null;
        $input['ipv6'] = filled($input['ipv6'] ?? null) ? $input['ipv6'] : null;
        $data = Validator::make($input, [
            'ipv4' => ['nullable', 'ipv4', Rule::unique('edge_pool_endpoints', 'ipv4')->ignore($endpoint)],
            'ipv6' => ['nullable', 'ipv6', Rule::unique('edge_pool_endpoints', 'ipv6')->ignore($endpoint)],
            'withdrawn' => ['sometimes', 'boolean'],
        ])->after(function ($validator) use ($input): void {
            if (! filled($input['ipv4'] ?? null) && ! filled($input['ipv6'] ?? null)) {
                $validator->errors()->add('ipv4', 'At least one service address is required.');
            }
            foreach (['ipv4', 'ipv6'] as $field) {
                $address = $input[$field] ?? null;
                if ($address && (NetworkAddress::isUnsafe($address) || Edge::query()->where('management_ipv4', $address)->orWhere('management_ipv6', $address)->exists())) {
                    $validator->errors()->add($field, 'The endpoint must use a public unicast address distinct from management addresses.');
                }
            }
        })->validate();

        return $data;
    }
}
