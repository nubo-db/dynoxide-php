<?php

declare(strict_types=1);

namespace Dynoxide;

use Dynoxide\Binary\Locator;
use Dynoxide\Cache\Installer;
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
        $env = getenv();
        $binary = Locator::forEmbeddedRelease($env)->locate($options->binary, $options->binaryFromPath);

        return (new Launcher($env))->start($binary, $options);
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
