<?php

namespace App\Support;

/**
 * The rejection reasons an edge agent reports. The list lives in
 * resources/contracts/edge-rejection-reasons.json; the agent embeds the same
 * file and tests/contract/test_edge_rejection_reasons.py keeps them equal.
 */
final class EdgeRejectionReasons
{
    /** @return list<string> */
    public static function all(): array
    {
        $decoded = json_decode((string) file_get_contents(resource_path('contracts/edge-rejection-reasons.json')), true, flags: JSON_THROW_ON_ERROR);

        return array_values($decoded['reasons']);
    }
}
