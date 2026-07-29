<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CommandNamespaceTest extends TestCase
{
    private const DOCUMENTATION = '../docs/operations/cli-commands.md';

    public function test_every_application_command_uses_the_cdnf_namespace(): void
    {
        $commands = Artisan::all();
        $owned = collect($commands)->filter(
            fn ($command): bool => $command instanceof Command
                && str_starts_with($command::class, 'App\\Console\\Commands\\')
        );

        $this->assertNotEmpty($owned);
        $owned->each(function (Command $command, string $name): void {
            $this->assertStringStartsWith('cdnf:', $name, 'Application command '.get_class($command).' is not namespaced.');
            $this->assertStringNotContainsString('cdfn:', $name);
        });
    }

    public function test_framework_and_third_party_commands_keep_their_names(): void
    {
        foreach (['migrate', 'queue:work', 'schedule:run', 'horizon:snapshot', 'model:prune', 'cache:clear'] as $name) {
            $this->assertArrayHasKey($name, Artisan::all());
            $this->assertArrayNotHasKey("cdnf:{$name}", Artisan::all());
        }
    }

    public function test_scheduled_command_names_exist(): void
    {
        $registry = Artisan::all();
        $events = app(Schedule::class)->events();
        $scheduledNames = collect($events)->map(fn ($event): ?string => preg_match('/artisan(?:\'|\")?\s+([^\s\'\"]+)/', $event->command, $matches) ? $matches[1] : null)
            ->filter()->values();

        $this->assertNotEmpty($scheduledNames);
        $scheduledNames->each(fn (string $name) => $this->assertArrayHasKey($name, $registry, "Scheduled command {$name} is not registered."));
    }

    public function test_public_cdnf_commands_and_documentation_match(): void
    {
        $document = file_get_contents(base_path(self::DOCUMENTATION));
        preg_match_all('/`(cdnf:[a-z0-9:-]+)(?:\s[^`]*)?`/', $document, $matches);
        $documented = collect($matches[1])->unique()->sort()->values();
        $registered = collect(array_keys(Artisan::all()))->filter(fn (string $name): bool => str_starts_with($name, 'cdnf:'))->sort()->values();

        $this->assertSame($registered->all(), $documented->all(), 'The public cdnf command registry and CLI documentation differ.');
    }
}
