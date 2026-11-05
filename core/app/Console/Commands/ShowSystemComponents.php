<?php

namespace App\Console\Commands;

use App\Support\SystemHealth;
use Illuminate\Console\Command;

class ShowSystemComponents extends Command
{
    protected $signature = 'cdnf:system:components';

    protected $description = 'Print the control plane component and queue health as JSON for Fleet doctor';

    public function handle(SystemHealth $health): int
    {
        $components = $health->components();
        $this->line(json_encode(['status' => $health->overall($components), 'components' => $components, 'queues' => $health->queues()], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
