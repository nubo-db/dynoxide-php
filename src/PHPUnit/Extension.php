<?php

declare(strict_types=1);

namespace Dynoxide\PHPUnit;

use Dynoxide\Dynoxide;
use Dynoxide\Exception\DynoxideException;
use Dynoxide\Options;
use Dynoxide\Server;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Runner\Extension\Extension as PhpunitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Starts a Dynoxide server before the tests run, exports its environment for
 * AWS SDKs to find, and stops it afterwards. In phpunit.xml:
 *
 *     <extensions>
 *         <bootstrap class="Dynoxide\PHPUnit\Extension">
 *             <parameter name="db-path" value="var/dynoxide.db"/>
 *         </bootstrap>
 *     </extensions>
 *
 * Parameters, all optional: binary, binary-from-path (true or false),
 * db-path, schema and timeout (seconds). Relative paths are relative to the
 * configuration file. Under ParaTest or Pest --parallel each worker gets its
 * own server, and db-path gets the worker's TEST_TOKEN appended.
 *
 * The server starts when the tests start, so data providers and PHPUnit's
 * bootstrap file run before it exists and can't see its endpoint.
 */
final class Extension implements PhpunitExtension
{
    private const PARAMETERS = ['binary', 'binary-from-path', 'db-path', 'schema', 'timeout'];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $base = $configuration->hasConfigurationFile() ? dirname($configuration->configurationFile()) : (string) getcwd();
        try {
            $start = self::starter(self::read($parameters), $base);
        } catch (DynoxideException $e) {
            // Reported when the tests start, like any other failure to start,
            // so the tests are still pointed away from AWS.
            $start = static fn (): Server => throw $e;
        }
        $session = new Session($start);
        try {
            $facade->registerSubscribers(new StartSubscriber($session), new StopSubscriber($session));
        } catch (EventFacadeIsSealedException) {
            // The ParaTest parent, which Pest --parallel also runs, loads
            // extensions after the events are sealed. It runs no tests, so it
            // needs no server; its workers each start their own.
        }
    }

    /**
     * Turns the parameters into a function that starts the server. Kept apart
     * from bootstrap() so it can be tested without PHPUnit's runner.
     *
     * @internal
     *
     * @param array<string, string> $parameters
     * @param (\Closure(Options): Server)|null $start what starts it, Dynoxide::start() unless a test says otherwise
     * @return \Closure(): Server
     */
    public static function starter(array $parameters, string $base, ?\Closure $log = null, ?\Closure $start = null): \Closure
    {
        $start ??= static fn (Options $options): Server => Dynoxide::start($options);
        $path = static fn (?string $value): ?string => $value === null || $value === '' ? null : self::resolve($value, $base);
        $fromPath = self::flag($parameters['binary-from-path'] ?? 'false');
        $timeout = $parameters['timeout'] ?? '10';
        if (!is_numeric($timeout)) {
            throw new DynoxideException("The Dynoxide extension's timeout parameter must be a number of seconds, not \"{$timeout}\".");
        }
        $binary = $path($parameters['binary'] ?? null);
        $dbPath = $path($parameters['db-path'] ?? null);
        $schema = $path($parameters['schema'] ?? null);

        return static function () use ($binary, $fromPath, $dbPath, $schema, $timeout, $log, $start): Server {
            $options = new Options(
                binary: $binary,
                binaryFromPath: $fromPath,
                dbPath: $dbPath === null ? null : self::workerDatabase($dbPath),
                schema: $schema,
                startTimeout: (float) $timeout,
                log: $log,
            );
            if ($options->dbPath !== $dbPath) {
                ($options->logger())('Dynoxide: parallel worker ' . getenv('TEST_TOKEN') . " uses the database {$options->dbPath}");
            }

            return $start($options);
        };
    }

    /**
     * The database file for this process: the one given, or in a ParaTest
     * worker (Pest --parallel included) that file with the worker's number
     * appended, since two servers can't share one file. ParaTest sets
     * PARATEST in every worker; TEST_TOKEN alone is a common name for
     * unrelated secrets, so it counts only alongside PARATEST.
     */
    private static function workerDatabase(string $dbPath): string
    {
        $paratest = getenv('PARATEST');
        if ($paratest === false || $paratest === '') {
            return $dbPath;
        }
        $token = getenv('TEST_TOKEN');
        if ($token === false || preg_match('/^\d+$/', $token) !== 1) {
            throw new DynoxideException(
                'ParaTest is running without worker numbers (TEST_TOKEN), as with --no-test-tokens, so its workers '
                . "can't each have their own database file and would share {$dbPath}. Run without --no-test-tokens, "
                . 'or without the db-path parameter.',
            );
        }

        return "{$dbPath}-{$token}";
    }

    /** @return array<string, string> */
    private static function read(ParameterCollection $parameters): array
    {
        $values = [];
        foreach (self::PARAMETERS as $name) {
            if ($parameters->has($name)) {
                $values[$name] = $parameters->get($name);
            }
        }

        return $values;
    }

    private static function flag(string $value): bool
    {
        return match (strtolower(trim($value))) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off', '' => false,
            default => throw new DynoxideException(
                "The Dynoxide extension's binary-from-path parameter must be true or false, not \"{$value}\".",
            ),
        };
    }

    /** A path relative to the configuration file's directory, unless it's absolute. */
    private static function resolve(string $path, string $base): string
    {
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;

        return $absolute ? $path : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}
