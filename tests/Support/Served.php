<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

/**
 * A running fixture server or proxy, with its routes and request log.
 */
final class Served
{
    public const CERT = __DIR__ . '/../Fixtures/tls/server.pem';
    public const CA = __DIR__ . '/../Fixtures/tls/cert.pem';
    /** Trusted through BOTH_CA, but issued for wrong.example, not 127.0.0.1. */
    public const WRONG_NAME_CERT = __DIR__ . '/../Fixtures/tls/wrong-name-server.pem';
    public const BOTH_CA = __DIR__ . '/../Fixtures/tls/both-ca.pem';

    private readonly FixtureProcess $process;
    private readonly string $routesFile;
    private readonly string $logFile;

    /** @param array<string, array<string, mixed>> $routes */
    private function __construct(string $dir, string $script, array $args, array $routes, private readonly string $scheme)
    {
        $id = bin2hex(random_bytes(4));
        $this->routesFile = "{$dir}/routes-{$id}.json";
        $this->logFile = "{$dir}/log-{$id}.jsonl";
        file_put_contents($this->logFile, '');
        $this->routes($routes);
        $args = array_map(fn (string $a): string => strtr($a, ['{routes}' => $this->routesFile, '{log}' => $this->logFile]), $args);
        $this->process = new FixtureProcess($script, $args, "{$dir}/stderr-{$id}.log");
    }

    /** @param array<string, array<string, mixed>> $routes */
    public static function http(string $dir, array $routes = []): self
    {
        return new self($dir, 'http-server.php', ['{routes}', '{log}'], $routes, 'http');
    }

    /** @param array<string, array<string, mixed>> $routes */
    public static function https(string $dir, array $routes = [], string $cert = self::CERT): self
    {
        return new self($dir, 'http-server.php', ['{routes}', '{log}', realpath($cert) ?: $cert], $routes, 'https');
    }

    /** @param float $delay seconds the reply to CONNECT waits */
    public static function proxy(string $dir, ?string $credentials = null, float $delay = 0.0): self
    {
        return new self($dir, 'http-proxy.php', ['{log}', $credentials ?? '', (string) $delay], [], 'http');
    }

    /** @param array<string, array<string, mixed>> $routes */
    public function routes(array $routes): void
    {
        file_put_contents($this->routesFile, json_encode($routes, JSON_FORCE_OBJECT));
    }

    public function url(string $path = '', string $host = '127.0.0.1'): string
    {
        return "{$this->scheme}://{$host}:{$this->process->port}{$path}";
    }

    public function port(): int
    {
        return $this->process->port;
    }

    /** @return list<array{method: string, target: string, headers: array<string, string>}> */
    public function log(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));

        return array_values(array_map(static fn (string $l): array => json_decode($l, true), $lines));
    }

    public function stop(): void
    {
        $this->process->stop();
    }
}
