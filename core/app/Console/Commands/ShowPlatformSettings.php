<?php

namespace App\Console\Commands;

use App\Support\PlatformSettings;
use Illuminate\Console\Command;

class ShowPlatformSettings extends Command
{
    protected $signature = 'platform:settings:show {group?} {--json}';

    protected $description = 'Show PostgreSQL-backed platform settings, descriptions, and defaults';

    public function handle(PlatformSettings $settings): int
    {
        $group = $this->argument('group');
        $data = $settings->present($group === null ? null : (string) $group);
        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        foreach ($data as $item) {
            $this->newLine();
            $this->info("{$item['label']} [{$item['group']}] revision {$item['revision']}");
            $this->line($item['description']);
            $this->table(['Setting', 'Current value', 'Default', 'Description'], collect($item['fields'])->map(fn (array $field): array => [
                $field['key'], self::display($field['value']), self::display($field['default']), $field['description'],
            ])->all());
        }

        return self::SUCCESS;
    }

    private static function display(mixed $value): string
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : var_export($value, true);
    }
}
