<?php

declare(strict_types=1);

namespace Dynoxide;

use Dynoxide\Binary\LocatedBinary;
use Dynoxide\Binary\Locator;
use Dynoxide\Cache\Installer;
use Dynoxide\Exception\BinaryException;
use Dynoxide\Process\Launcher;

/**
 * Starts Dynoxide servers.
 *
 *     $server = Dynoxide::start();
 *     $client = new DynamoDbClient($server->clientConfig());
 *     // ...
 *     $server->stop();
 */
final class Dynoxide
{
    /**
     * Finds or downloads the binary, starts a server on a free port on
     * 127.0.0.1, and returns once it's ready.
     *
     * @param Options|array<string, mixed> $options
     */
    public static function start(Options|array $options = []): Server
    {
        $options = $options instanceof Options ? $options : Options::fromArray($options);

        return (new Launcher(getenv()))->start(self::locate($options), $options);
    }

    /**
     * Finds the binary start() would run, without starting it: the binary
     * option, or the dynoxide on PATH with binaryFromPath, then
     * DYNOXIDE_BINARY, then the verified download in the cache, downloading
     * it first if it isn't there. The other options are ignored.
     *
     * @param Options|array<string, mixed> $options
     * @throws BinaryException when the binary chosen isn't there, can't be
     *         run, or is older than 1.6.0, with the message start() gives
     */
    public static function locate(Options|array $options = []): LocatedBinary
    {
        $options = $options instanceof Options ? $options : Options::fromArray($options);

        return Locator::forEmbeddedRelease(getenv())->locate($options->binary, $options->binaryFromPath);
    }

    /**
     * Downloads and verifies the binary this package runs, if it isn't in the
     * cache already, and returns its path. With $force, it's downloaded again.
     */
    public static function install(bool $force = false): string
    {
        return Installer::forEmbeddedRelease(getenv())->install($force);
    }
}
