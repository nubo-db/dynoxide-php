<?php

declare(strict_types=1);

namespace Dynoxide;

/**
 * Environment variables as the package reads them.
 */
final class Environment
{
    /**
     * On Windows, variable names are case-insensitive, and getenv() returns
     * them as they were set ("Path", say). Each name there also gets an
     * upper-case alias, so a lookup of "PATH" or "DYNOXIDE_CACHE_DIR" finds
     * it however it was written. Names are kept as given too, and on other
     * systems nothing changes.
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    public static function normalise(array $env, string $osFamily = PHP_OS_FAMILY): array
    {
        if ($osFamily !== 'Windows') {
            return $env;
        }
        foreach ($env as $name => $value) {
            $upper = strtoupper((string) $name);
            if (!isset($env[$upper])) {
                $env[$upper] = $value;
            }
        }

        return $env;
    }
}
