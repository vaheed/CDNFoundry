<?php

namespace Tests\Unit;

use App\Support\OriginData;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OriginResolutionCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        OriginData::releaseResolutions();
        parent::tearDown();
    }

    public function test_a_prepared_failure_is_answered_from_memory_until_released(): void
    {
        // A name that cannot resolve fails once, outside any lock; later uses replay that result.
        OriginData::prepareResolutions(['never-resolves.invalid']);

        $first = $this->failure('never-resolves.invalid');
        $second = $this->failure('NEVER-RESOLVES.invalid');

        $this->assertSame($first, $second, 'the same remembered failure is replayed, not looked up again');

        OriginData::releaseResolutions();
        $this->assertNotSame($first, $this->failure('never-resolves.invalid'), 'releasing forgets the remembered result');
    }

    private function failure(string $host): ValidationException
    {
        try {
            OriginData::resolveAndValidate($host);
        } catch (ValidationException $exception) {
            return $exception;
        }
        $this->fail('The host must not resolve.');
    }
}
