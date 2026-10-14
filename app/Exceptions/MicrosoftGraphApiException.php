<?php

namespace App\Exceptions;

use RuntimeException;

class MicrosoftGraphApiException extends RuntimeException
{
    /**
     * @param  array{
     *     failure_category?: string|null,
     *     method?: string,
     *     request_url?: string,
     *     http_status?: int|null,
     *     graph_error_code?: string|null,
     *     graph_error_message?: string|null,
     *     correlation_id?: string|null,
     *     request_id?: string|null,
     *     jwt_claims?: array{aud: string|list<string>|null, tid: string|null, appid: string|null, roles: list<string>, scp: string|null},
     *     token_acquired?: bool,
     *     token_source?: string,
     *     token_expiration?: string|null,
     *     audience_claim?: string|list<string>|null,
     *     tenant_id?: string,
     *     token_tenant_id?: string|null,
     *     aad_error_code?: string|null,
     *     aad_error_description?: string|null,
     *     failure_reason?: string
     * }|null  $debugContext
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?array $debugContext = null,
    ) {
        parent::__construct($reason);
    }

    public function localDebugMessage(): ?string
    {
        if ($this->debugContext === null) {
            return null;
        }

        $details = [];
        $category = match ($this->debugContext['failure_category'] ?? null) {
            'authentication_failure' => 'Authentication failure',
            'authorization_failure' => 'Authorization failure',
            'resource_not_found' => 'Resource not found',
            'graph_service_failure' => 'Graph service failure',
            'graph_request_failure' => 'Graph request failure',
            default => null,
        };

        if ($category !== null) {
            $details[] = $category;
        }

        $httpStatus = $this->debugContext['http_status'] ?? null;

        if (is_int($httpStatus)) {
            $prefix = array_key_exists('aad_error_code', $this->debugContext) || array_key_exists('aad_error_description', $this->debugContext)
                ? 'AAD HTTP '
                : 'HTTP ';
            $details[] = $prefix.$httpStatus;
        }

        $errorCode = $this->debugContext['aad_error_code'] ?? $this->debugContext['graph_error_code'] ?? null;
        $errorMessage = $this->debugContext['aad_error_description'] ?? $this->debugContext['graph_error_message'] ?? null;

        if (is_string($errorCode) && $errorCode !== '') {
            $details[] = $errorCode;
        }

        if (is_string($errorMessage) && $errorMessage !== '') {
            $details[] = $errorMessage;
        }

        if (is_array($this->debugContext['jwt_claims'] ?? null)) {
            $claims = json_encode($this->debugContext['jwt_claims'], JSON_UNESCAPED_SLASHES);

            if (is_string($claims)) {
                $details[] = 'JWT claims '.$claims;
            }
        }

        return $details === [] ? null : implode(' — ', $details);
    }
}
