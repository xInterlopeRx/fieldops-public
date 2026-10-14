<?php

namespace App\Services;

class BwsSecretHelper
{
    public const HELPER_COMMAND = '/usr/local/bin/fieldops-bws-helper';

    public static function command(): string
    {
        $configuredCommand = config('services.bitwarden.helper_command');

        if (is_string($configuredCommand) && trim($configuredCommand) !== '') {
            return $configuredCommand;
        }

        if (app()->environment('local')) {
            $hostCommand = storage_path('app/private/fieldops-bws-helper');

            if (is_executable($hostCommand) && self::hostHelperIsCurrent($hostCommand)) {
                return $hostCommand;
            }
        }

        return self::HELPER_COMMAND;
    }

    private static function hostHelperIsCurrent(string $helperCommand): bool
    {
        $helperTimestamp = filemtime($helperCommand);

        if ($helperTimestamp === false) {
            return false;
        }

        foreach ([
            base_path('docker/bws-helper/Cargo.toml'),
            base_path('docker/bws-helper/Cargo.lock'),
            base_path('docker/bws-helper/src/main.rs'),
            base_path('docker/php/Dockerfile'),
        ] as $source) {
            $sourceTimestamp = filemtime($source);

            if ($sourceTimestamp !== false && $sourceTimestamp > $helperTimestamp) {
                return false;
            }
        }

        return true;
    }
}
