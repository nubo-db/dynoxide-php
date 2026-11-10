<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Options;
use Dynoxide\Server;
use Dynoxide\Tests\Support\FakeServer;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    private const NAMES = [
        'AWS_ENDPOINT_URL_DYNAMODB', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION',
        'AWS_DEFAULT_REGION', 'DYNAMODB_ENDPOINT', 'AWS_ENDPOINT_URL', 'AWS_SESSION_TOKEN',
    ];

    private ?Server $server = null;

    /** @var array<string, array{string|false, mixed, mixed}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
            $this->saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->server = FakeServer::launcher()->start(FakeServer::binary(), new Options(log: static function (): void {
        }));
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
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

    public function testExportsTheContractsVariablesEveryWayPhpReadsThem(): void
    {
        $this->server?->exportEnv();

        $expected = [
            'AWS_ENDPOINT_URL_DYNAMODB' => 'http://127.0.0.1:54321',
            'AWS_ACCESS_KEY_ID' => 'dynoxide',
            'AWS_SECRET_ACCESS_KEY' => 'dynoxide',
            'AWS_REGION' => 'us-east-1',
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'DYNAMODB_ENDPOINT' => 'http://127.0.0.1:54321',
        ];
        foreach ($expected as $name => $value) {
            self::assertSame([$value, $value, $value], [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null], $name);
        }
    }

    public function testRemovesARealSessionToken(): void
    {
        putenv('AWS_SESSION_TOKEN=real-token-from-ci');
        $_ENV['AWS_SESSION_TOKEN'] = $_SERVER['AWS_SESSION_TOKEN'] = 'real-token-from-ci';

        $this->server?->exportEnv();

        self::assertSame(
            [false, null, null],
            [getenv('AWS_SESSION_TOKEN'), $_ENV['AWS_SESSION_TOKEN'] ?? null, $_SERVER['AWS_SESSION_TOKEN'] ?? null],
        );
    }

    public function testNeverSetsTheEndpointForEveryService(): void
    {
        $this->server?->exportEnv();

        self::assertSame([false, null, null], [getenv('AWS_ENDPOINT_URL'), $_ENV['AWS_ENDPOINT_URL'] ?? null, $_SERVER['AWS_ENDPOINT_URL'] ?? null]);
    }

    public function testStartingExportsNothing(): void
    {
        self::assertFalse(getenv('AWS_ENDPOINT_URL_DYNAMODB'));
        self::assertFalse(getenv('AWS_ACCESS_KEY_ID'));
    }

    public function testGivesTheSdkItsSettings(): void
    {
        self::assertSame(
            [
                'endpoint' => 'http://127.0.0.1:54321',
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'dynoxide', 'secret' => 'dynoxide'],
            ],
            $this->server?->clientConfig(),
        );
    }
}
