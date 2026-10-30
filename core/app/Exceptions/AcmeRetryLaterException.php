<?php

namespace App\Exceptions;

use RuntimeException;

/** The ACME server asked the client to wait (HTTP 429 or 503 with Retry-After). */
class AcmeRetryLaterException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }
}
