<?php

namespace App\Console\Commands;

use App\Exceptions\MicrosoftGraphApiException;
use App\Services\MicrosoftGraphSharePointService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('graph:sharepoint:discover-libraries {site-id? : Site ID (defaults to MICROSOFT_GRAPH_WORK_SCHEDULES_SITE_ID)}')]
#[Description('List document libraries in one configured SharePoint site for validation.')]
class DiscoverSharePointLibraries extends Command
{
    public function handle(MicrosoftGraphSharePointService $sharepoint): int
    {
        try {
            $siteId = $this->argument('site-id');
            $libraries = $sharepoint->discoverLibraries(is_string($siteId) ? $siteId : null);
        } catch (MicrosoftGraphApiException $exception) {
            $reason = app()->environment('local')
                ? ($exception->localDebugMessage() ?? $exception->reason)
                : $exception->reason;
            $this->error('SharePoint library discovery failed: '.$reason);

            return self::FAILURE;
        }

        if ($libraries === []) {
            $this->comment('No document libraries were returned for the selected site.');
        } else {
            $this->table(
                ['List ID', 'Library', 'Drive ID', 'Drive Name', 'URL'],
                array_map(static fn (array $library): array => array_values($library), $libraries),
            );
        }

        $this->comment('Read-only library metadata; no SharePoint content was read or written.');

        return self::SUCCESS;
    }
}
