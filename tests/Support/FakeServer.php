<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

use Dynoxide\Binary\LocatedBinary;
use Dynoxide\Binary\Source;
use Dynoxide\Process\Launcher;

/**
 * A launcher that runs tests/Fixtures/fake-server.php in place of the
 * binary, with FAKE_MODE and friends in its environment.
 */
final class FakeServer
{
    public const SCRIPT = __DIR__ . '/../Fixtures/fake-server.php';

    /**
     * @param array<string, string> $env added to this process's environment
     */
    public static function launcher(
        array $env = [],
        float $graceSeconds = 5.0,
        ?string $tempRoot = null,
        string $osFamily = PHP_OS_FAMILY,
    ): Launcher {
        $launcher = new Launcher(array_merge(getenv(), $env), $osFamily);
        (function () use ($graceSeconds, $tempRoot): void {
            $this->command = static fn (string $path): array => [PHP_BINARY, $path];
            $this->graceSeconds = $graceSeconds;
            if ($tempRoot !== null) {
                $this->tempRoot = $tempRoot;
            }
        })->call($launcher);

        return $launcher;
    }

    /** Runs the binary itself, for a fake that's a real executable. */
    public static function direct(Launcher $launcher): Launcher
    {
        (function (): void {
            $this->command = static fn (string $path): array => [$path];
        })->call($launcher);

        return $launcher;
    }

    public static function binary(bool $launcherFlags = true, string $path = self::SCRIPT): LocatedBinary
    {
        return new LocatedBinary(realpath($path) ?: $path, Source::Option, $launcherFlags ? '1.7.0' : '1.6.0', $launcherFlags);
    }
}
