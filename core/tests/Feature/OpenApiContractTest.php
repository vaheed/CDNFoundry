<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    public function test_committed_openapi_contract_matches_routes(): void
    {
        $this->artisan('api:openapi', ['--check' => true])->assertSuccessful();
    }
}
