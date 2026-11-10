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
 * An item written by one server is there for the next one started on the
 * same database file, and only then.
 *
 * DYNOXIDE_TEST_BINARY names a 1.7.0 or later binary; without it these tests
 * are skipped.
 */
final class PersistenceTest extends TestCase
{
    use TempDir;

    private ?Server $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testADatabaseFileKeepsItemsBetweenServers(): void
    {
        $dbPath = $this->tempDir() . DIRECTORY_SEPARATOR . 'kept.db';

        self::write($this->start($dbPath)->endpoint);
        self::assertSame(0, $this->server?->stop());
        self::assertFileExists($dbPath);

        self::assertSame(['S' => 'still here'], self::read($this->start($dbPath)->endpoint));
    }

    public function testWithoutADatabaseFileTheNextServerStartsEmpty(): void
    {
        self::write($this->start(null)->endpoint);
        self::assertSame(0, $this->server?->stop());

        [, $body] = Dynamo::call($this->start(null)->endpoint, 'ListTables');
        self::assertSame([], $body['TableNames'] ?? null);
    }

    private function start(?string $dbPath): Server
    {
        $binary = getenv('DYNOXIDE_TEST_BINARY');
        if ($binary === false || $binary === '') {
            self::markTestSkipped('Set DYNOXIDE_TEST_BINARY to a Dynoxide 1.7.0 or later binary.');
        }

        return $this->server = Dynoxide::start(new Options(binary: $binary, dbPath: $dbPath, log: static function (): void {
        }));
    }

    /** Creates a table and writes one item. The hard-kill tests use it too. */
    public static function write(string $endpoint): void
    {
        self::assertSame(200, Dynamo::call($endpoint, 'CreateTable', [
            'TableName' => 'kept',
            'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
            'BillingMode' => 'PAY_PER_REQUEST',
        ])[0]);
        self::assertSame(200, Dynamo::call($endpoint, 'PutItem', [
            'TableName' => 'kept',
            'Item' => ['id' => ['S' => 'one'], 'note' => ['S' => 'still here']],
        ])[0]);
    }

    /** @return array<string, string>|null */
    public static function read(string $endpoint): ?array
    {
        [$status, $body] = Dynamo::call($endpoint, 'GetItem', [
            'TableName' => 'kept',
            'Key' => ['id' => ['S' => 'one']],
        ]);
        self::assertSame(200, $status, json_encode($body) ?: '');

        return $body['Item']['note'] ?? null;
    }
}
