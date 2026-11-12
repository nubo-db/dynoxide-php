<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Exception\DynoxideException;
use Dynoxide\Parallel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParallelTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['TEST_TOKEN', 'PARATEST'] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testOutsideParaTestTheFileIsUnchanged(): void
    {
        self::assertSame('/data/test.db', Parallel::workerDatabase('/data/test.db'));
    }

    public function testATestTokenOutsideParaTestIsLeftAlone(): void
    {
        putenv('TEST_TOKEN=ghp_notaworker');

        self::assertSame('/data/test.db', Parallel::workerDatabase('/data/test.db'));
    }

    public function testAParaTestWorkerGetsItsNumberAppended(): void
    {
        putenv('PARATEST=1');
        putenv('TEST_TOKEN=3');

        self::assertSame('/data/test.db-3', Parallel::workerDatabase('/data/test.db'));
    }

    /** @return iterable<string, array{string|null}> */
    public static function missingTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'not a number' => ['abc/def'];
    }

    #[DataProvider('missingTokens')]
    public function testAParaTestWorkerWithoutANumberRefusesToShareTheFile(?string $token): void
    {
        putenv('PARATEST=1');
        if ($token !== null) {
            putenv("TEST_TOKEN={$token}");
        }

        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage(
            "ParaTest is running without worker numbers (TEST_TOKEN), as with --no-test-tokens, so its workers can't each "
            . 'have their own database file and would share /data/test.db. Run without --no-test-tokens, or without a '
            . 'database file.',
        );
        Parallel::workerDatabase('/data/test.db');
    }
}
