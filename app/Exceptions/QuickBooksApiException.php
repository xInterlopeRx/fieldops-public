<?php

namespace App\Exceptions;

use RuntimeException;

class QuickBooksApiException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $statusCode,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($reason);
    }

    public function isRetryable(): bool
    {
        return $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
