<?php

namespace App\Console\Commands;

use App\Exceptions\MicrosoftGraphApiException;
use App\Services\MicrosoftGraphSharePointService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('graph:sharepoint:discover-drives {site-id? : Site ID (defaults to MICROSOFT_GRAPH_WORK_SCHEDULES_SITE_ID)}')]
#[Description('List drives in one configured SharePoint site for validation.')]
class DiscoverSharePointDrives extends Command
{
    public function handle(MicrosoftGraphSharePointService $sharepoint): int
    {
        try {
            $siteId = $this->argument('site-id');
            $drives = $sharepoint->discoverDrives(is_string($siteId) ? $siteId : null);
        } catch (MicrosoftGraphApiException $exception) {
            $reason = app()->environment('local')
                ? ($exception->localDebugMessage() ?? $exception->reason)
                : $exception->reason;
            $this->error('SharePoint drive discovery failed: '.$reason);

            return self::FAILURE;
        }

        if ($drives === []) {
            $this->comment('No drives were returned for the selected site.');
        } else {
            $this->table(
                ['Drive ID', 'Name', 'Type', 'URL', 'List ID'],
                array_map(static fn (array $drive): array => array_values($drive), $drives),
            );
        }

        $this->comment('Read-only drive metadata; no SharePoint content was read or written.');

        return self::SUCCESS;
    }
}
