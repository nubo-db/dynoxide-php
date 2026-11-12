<?php

declare(strict_types=1);

namespace Dynoxide\PHPUnit;

use Dynoxide\Dynoxide;
use Dynoxide\Exception\DynoxideException;
use Dynoxide\Exception\StartException;
use Dynoxide\Options;
use Dynoxide\Parallel;
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

    /**
     * This process's session. PHPUnit bootstraps an extension once per
     * process, before any test runs.
     */
    private static ?Session $session = null;

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
            return;
        }
        self::$session = $session;
    }

    /**
     * The server this extension started in this process, for code that runs
     * inside the tests and wants it rather than the environment, such as a
     * framework integration that would otherwise start a second one.
     *
     * Null when the extension isn't loaded in this process (a test run in a
     * separate process doesn't load it), before the tests start, and after
     * they've finished.
     *
     * @throws StartException the failure, when the server was meant to start
     *         and couldn't. The tests' environment then points at a port where
     *         nothing listens.
     */
    public static function server(): ?Server
    {
        return self::$session?->server();
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
                dbPath: $dbPath === null ? null : Parallel::workerDatabase($dbPath),
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
