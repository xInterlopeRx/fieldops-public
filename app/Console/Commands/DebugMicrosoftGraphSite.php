<?php

namespace App\Console\Commands;

use App\Exceptions\MicrosoftGraphApiException;
use App\Services\MicrosoftGraphSharePointService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('graph:debug:site')]
#[Description('Show local diagnostics for the configured SharePoint Site request.')]
class DebugMicrosoftGraphSite extends Command
{
    public function handle(MicrosoftGraphSharePointService $sharepoint): int
    {
        if (! app()->environment('local')) {
            $this->error('Graph diagnostics are available only in the local environment.');

            return self::FAILURE;
        }

        $siteId = config('services.microsoft_graph.work_schedules.site_id');
        $this->line('Site ID: '.(is_string($siteId) && trim($siteId) !== '' ? trim($siteId) : 'not configured'));
        $this->line('Auth mode: app-only client credentials (Graph /.default)');

        try {
            $endpointUrl = $sharepoint->siteEndpointUrl(is_string($siteId) ? $siteId : null);
        } catch (MicrosoftGraphApiException $exception) {
            $this->line('Endpoint URL: unavailable');
            $this->line('Response details:');
            $this->line('  Error: '.$exception->reason);

            return self::FAILURE;
        }

        $this->line('Endpoint URL: '.$endpointUrl);

        try {
            $site = $sharepoint->discoverSite(is_string($siteId) ? $siteId : null);
        } catch (MicrosoftGraphApiException $exception) {
            $this->line('Response details:');
            $this->renderErrorDetails($exception);

            return self::FAILURE;
        }

        $this->line('Response details:');
        $this->line('  HTTP status: 200');
        $this->line('  Site name: '.$site['name']);
        $this->line('  Site URL: '.($site['webUrl'] ?? 'not returned'));
        $this->line('  Host: '.($site['hostname'] ?? 'not returned'));

        return self::SUCCESS;
    }

    private function renderErrorDetails(MicrosoftGraphApiException $exception): void
    {
        $context = $exception->debugContext;

        if ($context === null) {
            $this->line('  Error: '.$exception->reason);

            return;
        }

        $this->line('  HTTP status: '.($context['http_status'] ?? 'unavailable'));
        $this->line('  Failure category: '.($context['failure_category'] ?? 'unavailable'));
        $isAadError = array_key_exists('aad_error_code', $context) || array_key_exists('aad_error_description', $context);
        $errorCodeLabel = $isAadError ? 'AAD error code' : 'Graph error code';
        $errorMessageLabel = $isAadError ? 'AAD error description' : 'Graph error message';
        $errorCode = $context[$isAadError ? 'aad_error_code' : 'graph_error_code'] ?? 'not returned';
        $errorMessage = $context[$isAadError ? 'aad_error_description' : 'graph_error_message'] ?? 'not returned';
        $this->line('  '.$errorCodeLabel.': '.$errorCode);
        $this->line('  '.$errorMessageLabel.': '.$errorMessage);
        $this->line('  Correlation ID: '.($context['correlation_id'] ?? 'not returned'));
        $this->line('  Request ID: '.($context['request_id'] ?? 'not returned'));

        if (is_array($context['jwt_claims'] ?? null)) {
            foreach (['aud', 'tid', 'appid', 'roles', 'scp'] as $claim) {
                $value = $context['jwt_claims'][$claim] ?? null;
                $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES);
                $this->line('  JWT claim '.$claim.': '.(is_string($encodedValue) ? $encodedValue : 'not available'));
            }
        }
    }
}
