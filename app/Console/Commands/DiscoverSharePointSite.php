<?php

namespace App\Console\Commands;

use App\Exceptions\MicrosoftGraphApiException;
use App\Services\MicrosoftGraphSharePointService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('graph:sharepoint:discover-site {site-id? : Site ID (defaults to MICROSOFT_GRAPH_WORK_SCHEDULES_SITE_ID)}')]
#[Description('Read metadata for one configured SharePoint site.')]
class DiscoverSharePointSite extends Command
{
    public function handle(MicrosoftGraphSharePointService $sharepoint): int
    {
        try {
            $siteId = $this->argument('site-id');
            $site = $sharepoint->discoverSite(is_string($siteId) ? $siteId : null);
        } catch (MicrosoftGraphApiException $exception) {
            $reason = app()->environment('local')
                ? ($exception->localDebugMessage() ?? $exception->reason)
                : $exception->reason;
            $this->error('SharePoint site discovery failed: '.$reason);

            return self::FAILURE;
        }

        $this->table(['Site ID', 'Name', 'URL', 'Host'], [array_values($site)]);
        $this->comment('Read-only site metadata; no SharePoint content was read or written.');

        return self::SUCCESS;
    }
}
