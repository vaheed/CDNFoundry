<?php

namespace Tests\Feature;

use App\Support\CacheKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Runs the fixture the Lua runtime also runs (tests/contract/test_cache_key_vectors.py). */
class CacheKeyVectorsTest extends TestCase
{
    public static function vectors(): iterable
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../../tests/fixtures/cache_key_vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['vectors'] as $vector) {
            if (isset($vector['target_repeat'])) {
                $repeat = $vector['target_repeat'];
                $vector['target'] = $repeat['prefix'].implode($repeat['separator'], array_fill(0, $repeat['count'], $repeat['item']));
            }
            yield $vector['name'] => [$vector];
        }
    }

    #[DataProvider('vectors')]
    public function test_target_matches_the_shared_vector(array $vector): void
    {
        $policy = $vector['policy'] ?? 'include_all';
        $parameters = $vector['parameters'] ?? [];
        if ($vector['expected'] === null) {
            try {
                CacheKey::target($vector['target'], $policy, $parameters);
                $this->fail('Expected the target to have no key.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($vector['reason'], $exception->getMessage());
            }

            return;
        }
        $this->assertSame($vector['expected'], CacheKey::target($vector['target'], $policy, $parameters));
    }
}
