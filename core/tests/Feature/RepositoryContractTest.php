<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryContractTest extends TestCase
{
    private const SKILLS = [
        'cdnf-feature-module',
        'cdnf-api-endpoint',
        'cdnf-filament-ui',
        'cdnf-reconciliation',
        'cdnf-dns-runtime',
        'cdnf-edge-runtime',
        'cdnf-tls-lifecycle',
        'cdnf-telemetry-analytics',
        'cdnf-compose-operations',
        'cdnf-production-qualification',
    ];

    public function test_root_agent_contract_contains_the_roadmap_forbidden_patterns(): void
    {
        $contract = file_get_contents(base_path('../AGENTS.md'));
        foreach (['microservices', 'custom RBAC', 'CQRS', 'repository wrappers', 'synchronous external effects', 'per-domain process'] as $rule) {
            $this->assertStringContainsStringIgnoringCase($rule, $contract);
        }

        $this->assertStringContainsString('must not launch Chromium, Playwright, Selenium, Cypress', $contract);
        $this->assertStringContainsString('Browser E2E qualification is manual and user-owned', $contract);
        $this->assertStringContainsString('uses Python under `tests/e2e`', $contract);
        $this->assertFileExists(base_path('../tests/e2e/e2e.py'));
        $this->assertFileDoesNotExist(base_path('../tests/e2e/phase1.mjs'));
    }

    #[DataProvider('skillProvider')]
    public function test_required_project_skill_has_every_contract_section(string $skill): void
    {
        $path = base_path("../.agents/skills/{$skill}/SKILL.md");
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        foreach (['Purpose', 'When to use', 'Required inputs', 'Files normally touched', 'Procedure', 'Validation commands', 'Definition of done', 'Stop conditions', 'Forbidden shortcuts', 'Expected completion summary'] as $heading) {
            $this->assertStringContainsString("## {$heading}", $contents);
        }
    }

    public static function skillProvider(): array
    {
        return array_map(fn (string $skill): array => [$skill], self::SKILLS);
    }
}
