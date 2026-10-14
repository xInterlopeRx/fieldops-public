<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;

class MicrosoftGraphSharePointService
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    private const SITE_SELECT = 'id,displayName,webUrl,siteCollection';

    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 100;

    private const MAX_ITEMS = self::PAGE_SIZE * self::MAX_PAGES;

    public function __construct(private readonly MicrosoftGraphApiClient $graph) {}

    /** @return array{id: string, name: string, webUrl: string|null, hostname: string|null} */
    public function discoverSite(?string $siteId = null): array
    {
        $this->ensureEnabled();
        $siteId = $this->siteId($siteId);
        $site = $this->graph->getJson('sites/'.rawurlencode($siteId), [
            '$select' => self::SITE_SELECT,
        ]);
        $id = $site['id'] ?? null;
        $name = $site['displayName'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
            throw new MicrosoftGraphApiException('graph_sharepoint_invalid_site_response', 502);
        }

        $webUrl = $site['webUrl'] ?? null;
        $hostname = data_get($site, 'siteCollection.hostname');

        return [
            'id' => $id,
            'name' => $name,
            'webUrl' => is_string($webUrl) ? $webUrl : null,
            'hostname' => is_string($hostname) ? $hostname : null,
        ];
    }

    public function siteEndpointUrl(?string $siteId = null): string
    {
        $siteId = $this->siteId($siteId);
        $path = 'sites/'.rawurlencode($siteId);
        $query = http_build_query(['$select' => self::SITE_SELECT], '', '&', PHP_QUERY_RFC3986);

        return self::GRAPH_BASE_URL.'/'.$path.'?'.$query;
    }

    /** @return list<array{id: string, name: string, driveType: string|null, webUrl: string|null, listId: string|null}> */
    public function discoverDrives(?string $siteId = null): array
    {
        $this->ensureEnabled();
        $siteId = $this->siteId($siteId);
        $drives = $this->getCollection('sites/'.rawurlencode($siteId).'/drives', [
            '$select' => 'id,name,driveType,webUrl,sharePointIds',
        ]);

        $normalizedDrives = array_map(function (array $drive): array {
            $id = $drive['id'] ?? null;
            $name = $drive['name'] ?? null;

            if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                throw new MicrosoftGraphApiException('graph_sharepoint_invalid_drive_response', 502);
            }

            return [
                'id' => $id,
                'name' => $name,
                'driveType' => is_string($drive['driveType'] ?? null) ? $drive['driveType'] : null,
                'webUrl' => is_string($drive['webUrl'] ?? null) ? $drive['webUrl'] : null,
                'listId' => is_string(data_get($drive, 'sharePointIds.listId'))
                    ? data_get($drive, 'sharePointIds.listId')
                    : null,
            ];
        }, $drives);

        usort($normalizedDrives, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $normalizedDrives;
    }

    /** @return list<array{id: string, name: string, webUrl: string|null, listId: string, driveId: string|null, driveName: string|null}> */
    public function discoverLibraries(?string $siteId = null): array
    {
        $this->ensureEnabled();
        $siteId = $this->siteId($siteId);
        $lists = $this->getCollection('sites/'.rawurlencode($siteId).'/lists', [
            '$select' => 'id,name,displayName,webUrl,list',
        ]);
        $drives = $this->discoverDrives($siteId);
        $drivesByListId = [];

        foreach ($drives as $drive) {
            if ($drive['listId'] !== null) {
                $drivesByListId[$drive['listId']] = $drive;
            }
        }

        $libraries = [];

        foreach ($lists as $list) {
            $template = data_get($list, 'list.template');

            if (! is_string($template) || strcasecmp($template, 'documentLibrary') !== 0) {
                continue;
            }

            $id = $list['id'] ?? null;
            $name = $list['displayName'] ?? $list['name'] ?? null;

            if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                throw new MicrosoftGraphApiException('graph_sharepoint_invalid_library_response', 502);
            }

            $drive = $drivesByListId[$id] ?? null;
            $libraries[] = [
                'id' => $id,
                'name' => $name,
                'webUrl' => is_string($list['webUrl'] ?? null) ? $list['webUrl'] : null,
                'listId' => $id,
                'driveId' => $drive['id'] ?? null,
                'driveName' => $drive['name'] ?? null,
            ];
        }

        usort($libraries, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $libraries;
    }

    /** @param array<string, string|int> $query
     * @return list<array<string, mixed>>
     */
    private function getCollection(string $path, array $query): array
    {
        $page = $this->graph->getJson($path, [...$query, '$top' => self::PAGE_SIZE]);
        $items = [];
        $pageCount = 0;

        do {
            $pageCount++;
            $values = $page['value'] ?? null;

            if (! is_array($values)) {
                throw new MicrosoftGraphApiException('graph_sharepoint_invalid_collection_response', 502);
            }

            foreach ($values as $value) {
                if (! is_array($value)) {
                    throw new MicrosoftGraphApiException('graph_sharepoint_invalid_collection_response', 502);
                }

                if (count($items) >= self::MAX_ITEMS) {
                    throw new MicrosoftGraphApiException('graph_sharepoint_page_limit_exceeded', 502);
                }

                $items[] = $value;
            }

            $nextLink = $page['@odata.nextLink'] ?? null;

            if ($nextLink === null) {
                break;
            }

            if (! is_string($nextLink) || $pageCount >= self::MAX_PAGES) {
                throw new MicrosoftGraphApiException('graph_sharepoint_page_limit_exceeded', 502);
            }

            $page = $this->graph->getJsonNextPage($nextLink);
        } while (true);

        return $items;
    }

    private function siteId(?string $siteId): string
    {
        $siteId = trim((string) ($siteId ?: config('services.microsoft_graph.work_schedules.site_id')));

        if (
            $siteId === '' ||
            strlen($siteId) > 512 ||
            preg_match('/[\/\\?#\r\n]/', $siteId) === 1 ||
            in_array('..', explode('/', $siteId), true)
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_site_id_invalid_or_missing', 422);
        }

        return $siteId;
    }

    private function ensureEnabled(): void
    {
        if (! config('services.microsoft_graph.sharepoint_enabled')) {
            throw new MicrosoftGraphApiException('graph_sharepoint_discovery_disabled', 503);
        }
    }
}
