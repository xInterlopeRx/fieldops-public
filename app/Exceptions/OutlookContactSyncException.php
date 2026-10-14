<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class OutlookContactSyncException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, previous: $previous);
    }
}
