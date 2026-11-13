<?php

namespace App\Exceptions;

use RuntimeException;

class FieldPulseApiException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($reason);
    }
}
