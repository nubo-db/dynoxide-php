<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Launcher;

use Dynoxide\Dynoxide;
use Dynoxide\Options;
use Dynoxide\Server;
use Dynoxide\Tests\Support\Dynamo;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Starts and stops the real binary. DYNOXIDE_TEST_BINARY names a 1.7.0 or
 * later build; without it these tests are skipped, which CI counts as a
 * failure on the legs that run this suite.
 */
final class StartStopTest extends TestCase
{
    use TempDir;

    /** @var list<Server> */
    private array $servers = [];

    /** @var list<string> */
    private array $logged = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    private function binary(): string
    {
        $binary = getenv('DYNOXIDE_TEST_BINARY');
        if ($binary === false || $binary === '') {
            self::markTestSkipped('Set DYNOXIDE_TEST_BINARY to a Dynoxide 1.7.0 or later binary.');
        }

        return $binary;
    }

    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] ??= getenv($name);
        putenv("{$name}={$value}");
    }

    private function start(?string $dbPath = null): Server
    {
        $server = Dynoxide::start(new Options(binary: $this->binary(), dbPath: $dbPath, log: function (string $line): void {
            $this->logged[] = $line;
        }));
        $this->servers[] = $server;

        return $server;
    }

    public function testStartsAServerThatAnswersAndStopsCleanly(): void
    {
        $server = $this->start();

        [$status, $body] = Dynamo::call($server->endpoint, 'ListTables');
        self::assertSame([200, []], [$status, $body['TableNames'] ?? null]);
        self::assertStringContainsString('from the binary option', $this->logged[0]);
        self::assertStringNotContainsString('started without the launcher flags', $this->logged[0], 'the normal start, not the fallback');

        $started = microtime(true);
        self::assertSame(0, $server->stop());
        self::assertLessThan(2.5, microtime(true) - $started, 'well within the 5 second grace period');
    }

    public function testTwoServersGetTheirOwnPortsAndStopApart(): void
    {
        $a = $this->start();
        $b = $this->start();
        self::assertNotSame($a->port, $b->port);

        Dynamo::call($a->endpoint, 'CreateTable', [
            'TableName' => 'only-in-a',
            'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);
        self::assertSame([], Dynamo::call($b->endpoint, 'ListTables')[1]['TableNames'] ?? null);

        // Closing A's stdin reaches A even though B started after it.
        $started = microtime(true);
        self::assertSame(0, $a->stop());
        self::assertLessThan(2.5, microtime(true) - $started, 'well within the 5 second grace period');
        self::assertSame(200, Dynamo::call($b->endpoint, 'ListTables')[0]);
    }

    /** Output goes to files, so heavy logging never fills a pipe and stalls requests. */
    public function testHeavyLoggingNeverStallsRequests(): void
    {
        $this->setEnv('RUST_LOG', 'debug');
        $server = $this->start();

        $slowest = 0.0;
        for ($i = 0; $i < 500; $i++) {
            $started = microtime(true);
            [$status] = Dynamo::call($server->endpoint, 'ListTables', timeout: 5.0);
            self::assertSame(200, $status);
            $slowest = max($slowest, microtime(true) - $started);
        }
        self::assertLessThan(3.0, $slowest);
        self::assertSame(0, $server->stop());
    }

    /**
     * The binary this package embeds, from the cache or a download. Before
     * 1.7.0 that's the fallback start, with a real binary. Needs the network,
     * so it runs only with DYNOXIDE_TEST_DOWNLOAD set.
     */
    public function testStartsTheEmbeddedReleaseWithNoOptions(): void
    {
        if (getenv('DYNOXIDE_TEST_DOWNLOAD') !== '1') {
            self::markTestSkipped('Set DYNOXIDE_TEST_DOWNLOAD=1 to download and start the embedded release.');
        }
        $this->setEnv('DYNOXIDE_BINARY', '');
        $server = Dynoxide::start(new Options(log: function (string $line): void {
            $this->logged[] = $line;
        }));
        $this->servers[] = $server;

        self::assertSame(200, Dynamo::call($server->endpoint, 'ListTables')[0]);
        self::assertStringContainsString('Dynoxide ' . \Dynoxide\Release::VERSION . ' at ', $this->logged[0]);
        self::assertSame(
            version_compare(\Dynoxide\Release::VERSION, '1.7.0', '<'),
            str_contains($this->logged[0], 'started without the launcher flags'),
        );
        $server->stop();
        self::assertFalse($server->isRunning());
    }

    public function testStartsWithAnEncryptionKeyInTheEnvironment(): void
    {
        $this->setEnv('DYNOXIDE_ENCRYPTION_KEY', str_repeat('ab', 32));

        $server = $this->start($this->tempDir() . '/plain.db');

        self::assertSame(200, Dynamo::call($server->endpoint, 'ListTables')[0]);
    }
}
