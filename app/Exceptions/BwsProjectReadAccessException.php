<?php

namespace App\Exceptions;

use RuntimeException;

class BwsProjectReadAccessException extends RuntimeException
{
    public const HELPER_UNAVAILABLE = 'helper_unavailable';

    public const REQUIRED_SECRETS_INVALID = 'required_secrets_invalid';

    public function __construct(public readonly string $reason = 'project_access_failed')
    {
        parent::__construct(match ($reason) {
            self::HELPER_UNAVAILABLE => 'The local BWS helper is not installed.',
            self::REQUIRED_SECRETS_INVALID => 'Required application secrets are missing or invalid in the BWS project.',
            default => 'BWS project read access could not be verified.',
        });
    }
}
