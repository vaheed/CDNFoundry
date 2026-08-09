<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class ConfigureStructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new OperationalContextProcessor);
    }
}
