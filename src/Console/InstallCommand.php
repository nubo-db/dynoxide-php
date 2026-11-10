<?php

declare(strict_types=1);

namespace Dynoxide\Console;

use Dynoxide\Cache\Installer;
use Dynoxide\Environment;
use Dynoxide\Exception\DynoxideException;

/**
 * What vendor/bin/dynoxide runs. Its one job is `install`: download and
 * verify the binary this package runs, ahead of the tests, so a CI cache can
 * be warmed or a machine prepared to work offline.
 *
 * The binary's path goes to stdout, ending in "\n" on every platform, and
 * everything else to stderr, so `$(vendor/bin/dynoxide install)` is the path.
 * It exits 0 on success and 1 on any failure or bad argument.
 *
 * @internal Run it through vendor/bin/dynoxide; the command line is the contract.
 */
final class InstallCommand
{
    public const USAGE = <<<'TXT'
        Usage: dynoxide install [--force]

        Downloads and verifies the Dynoxide binary this package runs, if it isn't
        in the cache already, and prints its path. --force downloads it again.

        The cache is DYNOXIDE_CACHE_DIR if that's set. DYNOXIDE_DOWNLOAD_BASE_URL
        points the download at a mirror of the release files.
        TXT;

    private const HELP = ['help', '--help', '-h'];

    /** @var \Closure(): Installer */
    private readonly \Closure $installer;

    /** @var array<string, string> */
    private readonly array $env;

    /**
     * @param (\Closure(): Installer)|null $installer what installs, the embedded release unless a test says otherwise
     * @param array<string, string>|null $env the environment, getenv() unless a test says otherwise
     */
    public function __construct(?\Closure $installer = null, ?array $env = null)
    {
        $env ??= getenv();
        $this->installer = $installer ?? static fn (): Installer => Installer::forEmbeddedRelease($env);
        $this->env = Environment::normalise($env);
    }

    /**
     * @param list<string> $args the arguments after the script name
     * @param resource $stdout
     * @param resource $stderr
     * @return int the exit status
     */
    public function run(array $args, $stdout, $stderr): int
    {
        if ($args === []) {
            fwrite($stderr, self::USAGE . PHP_EOL);

            return 1;
        }
        $options = array_slice($args, 1);
        if (in_array($args[0], self::HELP, true) || ($args[0] === 'install' && array_intersect($options, self::HELP) !== [])) {
            fwrite($stdout, self::USAGE . PHP_EOL);

            return 0;
        }
        if ($args[0] !== 'install') {
            fwrite($stderr, "dynoxide: unknown command \"{$args[0]}\"" . PHP_EOL . PHP_EOL . self::USAGE . PHP_EOL);

            return 1;
        }
        $unknown = array_values(array_diff($options, ['--force']));
        if ($unknown !== []) {
            fwrite($stderr, "dynoxide: install doesn't take \"{$unknown[0]}\"" . PHP_EOL . PHP_EOL . self::USAGE . PHP_EOL);

            return 1;
        }
        $force = in_array('--force', $options, true);

        try {
            $installer = ($this->installer)();
            $present = $force ? null : $installer->installed();
            $path = $present ?? $installer->install($force);
        } catch (DynoxideException $e) {
            fwrite($stderr, 'dynoxide: ' . $e->getMessage() . PHP_EOL);

            return 1;
        }

        fwrite($stderr, $present !== null
            ? "Dynoxide {$installer->version} is already installed." . PHP_EOL
            : "Installed Dynoxide {$installer->version}." . PHP_EOL);
        $override = $this->env['DYNOXIDE_BINARY'] ?? '';
        if ($override !== '') {
            fwrite($stderr, "DYNOXIDE_BINARY is set, so tests will run {$override} instead, unless they set the binary option." . PHP_EOL);
        }
        // "\n" rather than PHP_EOL: a shell's $(...) strips "\n" but would keep the "\r" of a Windows line ending.
        fwrite($stdout, $path . "\n");

        return 0;
    }
}
