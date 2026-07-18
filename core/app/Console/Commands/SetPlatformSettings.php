<?php

namespace App\Console\Commands;

use App\Support\PlatformSettings;
use Illuminate\Console\Command;
use JsonException;

class SetPlatformSettings extends Command
{
    protected $signature = 'platform:settings:set {group} {values : JSON object containing the fields to change}';

    protected $description = 'Validate and update one PostgreSQL-backed platform setting group';

    public function handle(PlatformSettings $settings): int
    {
        try {
            $values = json_decode((string) $this->argument('values'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('The values argument must be valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }
        if (! is_array($values) || array_is_list($values)) {
            $this->error('The values argument must be a JSON object.');

            return self::FAILURE;
        }
        $result = $settings->update((string) $this->argument('group'), $values);
        $this->info("Updated {$result['setting']->group} to revision {$result['setting']->revision}.");
        if ($result['operation'] !== null) {
            $this->line("Runtime reconciliation queued as operation {$result['operation']->getKey()}.");
        }

        return self::SUCCESS;
    }
}
