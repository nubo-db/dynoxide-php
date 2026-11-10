<?php

declare(strict_types=1);

namespace Dynoxide\Process;

/**
 * Every server this process started. Holding them here keeps each stdin pipe
 * open for as long as the server should run: if PHP freed the process handle,
 * it would close the pipe and the server would stop.
 *
 * One shutdown function stops whatever is still running when PHP exits. A
 * server leaves the Registry when it has stopped, so one that couldn't be
 * stopped stays and is tried again when its object is destroyed.
 *
 * @internal
 */
final class Registry
{
    /** @var array<int, ServerProcess> */
    private static array $servers = [];

    private static bool $hooked = false;

    public static function add(ServerProcess $process): void
    {
        self::$servers[spl_object_id($process)] = $process;
        if (!self::$hooked) {
            register_shutdown_function([self::class, 'stopAll']);
            self::$hooked = true;
        }
    }

    public static function remove(ServerProcess $process): void
    {
        unset(self::$servers[spl_object_id($process)]);
    }

    /** How many servers this process is holding, for tests. */
    public static function count(): int
    {
        return count(self::$servers);
    }

    /** Stops every server; one that throws doesn't keep the others running. */
    public static function stopAll(): void
    {
        foreach (self::$servers as $process) {
            try {
                $process->stop();
            } catch (\Throwable) {
                // It stays registered, and its destructor tries again.
            }
        }
    }
}
