<?php

namespace App\Logging;

use Monolog\Logger;

class ConfigureStructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new OperationalContextProcessor);
    }
}
