<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\PHPUnit;

use Dynoxide\Exception\StartException;
use Dynoxide\Options;
use Dynoxide\PHPUnit\Extension;
use Dynoxide\PHPUnit\Session;
use Dynoxide\Server;
use Dynoxide\Tests\Support\FakeServer;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private const NAMES = [
        'AWS_ENDPOINT_URL_DYNAMODB', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION',
        'AWS_DEFAULT_REGION', 'DYNAMODB_ENDPOINT', 'AWS_SESSION_TOKEN', 'AWS_IGNORE_CONFIGURED_ENDPOINT_URLS',
    ];

    /** @var array<string, array{string|false, mixed, mixed}> */
    private array $saved = [];

    private int $starts = 0;

    private ?Server $started = null;

    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
            $this->saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        }
    }

    protected function tearDown(): void
    {
        self::loadExtension(null);
        foreach ($this->saved as $name => [$env, $envArray, $server]) {
            putenv($env === false ? $name : "{$name}={$env}");
            unset($_ENV[$name], $_SERVER[$name]);
            if ($envArray !== null) {
                $_ENV[$name] = $envArray;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
        }
    }

    private function fake(): \Closure
    {
        return function (): Server {
            $this->starts++;

            return $this->started = FakeServer::launcher()->start(FakeServer::binary(), new Options(log: static function (): void {
            }));
        };
    }

    private function failing(): \Closure
    {
        return function (): Server {
            $this->starts++;
            throw new StartException('the database is locked');
        };
    }

    public function testStartsOnceExportsAndStops(): void
    {
        $session = new Session($this->fake());

        $session->start();
        $session->start();

        self::assertSame(1, $this->starts);
        $server = $this->started;
        self::assertNotNull($server);
        self::assertTrue($server->isRunning());
        self::assertSame('http://127.0.0.1:54321', getenv('AWS_ENDPOINT_URL_DYNAMODB'));
        self::assertSame('http://127.0.0.1:54321', $_SERVER['DYNAMODB_ENDPOINT'] ?? null);

        $session->stop();
        self::assertFalse($server->isRunning());
    }

    public function testAFailedStartPointsTheTestsAtAPortWhereNothingListens(): void
    {
        putenv('AWS_ENDPOINT_URL_DYNAMODB=https://dynamodb.eu-west-2.amazonaws.com');
        putenv('AWS_SESSION_TOKEN=real-token');
        putenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS=true');
        $session = new Session($this->failing());

        try {
            $session->start();
            self::fail('expected the start to fail');
        } catch (StartException $e) {
            self::assertMatchesRegularExpression(
                "#^Dynoxide didn't start, so this run's DynamoDB calls go to http://127\\.0\\.0\\.1:1, where nothing is listening\\. the database is locked$#",
                $e->getMessage(),
            );
        }

        $endpoint = (string) getenv('AWS_ENDPOINT_URL_DYNAMODB');
        self::assertSame('http://127.0.0.1:1', $endpoint);
        self::assertSame($endpoint, $_ENV['AWS_ENDPOINT_URL_DYNAMODB'] ?? null);
        self::assertSame('dynoxide', getenv('AWS_ACCESS_KEY_ID'));
        self::assertFalse(getenv('AWS_SESSION_TOKEN'));
        self::assertSame('false', getenv('AWS_IGNORE_CONFIGURED_ENDPOINT_URLS'));
        self::assertFalse(@stream_socket_client(str_replace('http://', 'tcp://', $endpoint), $errno, $errstr, 0.5));
    }

    public function testAFailedStartIsNotRetried(): void
    {
        $session = new Session($this->failing());
        try {
            $session->start();
        } catch (StartException) {
        }

        $session->start();
        $session->stop();

        self::assertSame(1, $this->starts);
    }

    /**
     * What bootstrap() does with the session, without PHPUnit's runner, or
     * with null, as in a process where the extension isn't loaded.
     */
    private static function loadExtension(?Session $session): void
    {
        \Closure::bind(static function () use ($session): void {
            self::$session = $session;
        }, null, Extension::class)();
    }

    public function testTheExtensionHasNoServerWhenItIsNotLoaded(): void
    {
        self::loadExtension(null);

        self::assertNull(Extension::server());
    }

    public function testTheExtensionHandsOutItsServerWhileItRuns(): void
    {
        $session = new Session($this->fake());
        self::loadExtension($session);
        self::assertNull(Extension::server(), 'not started yet');

        $session->start();
        self::assertNotNull($this->started);
        self::assertSame($this->started, Extension::server());

        $session->stop();
        self::assertNull(Extension::server(), 'stopped');
    }

    public function testTheExtensionThrowsTheFailureOfAStartThatFailed(): void
    {
        $session = new Session($this->failing());
        self::loadExtension($session);
        try {
            $session->start();
            self::fail('expected the start to fail');
        } catch (StartException $thrown) {
        }

        foreach ([1, 2] as $_) {
            try {
                Extension::server();
                self::fail('expected the recorded failure');
            } catch (StartException $e) {
                self::assertSame($thrown, $e);
            }
        }
        $session->stop();
        $this->expectExceptionObject($thrown);
        Extension::server();
    }
}
