<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\PHPUnit;

use Dynoxide\Exception\DynoxideException;
use Dynoxide\Options;
use Dynoxide\PHPUnit\Extension;
use Dynoxide\Server;
use PHPUnit\Framework\TestCase;

final class ExtensionTest extends TestCase
{
    private ?Options $options = null;

    /** @var list<string> */
    private array $logged = [];

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

    /** @param array<string, string> $parameters */
    private function optionsFor(array $parameters, string $base = '/project/config'): Options
    {
        $starter = Extension::starter(
            $parameters,
            $base,
            function (string $line): void {
                $this->logged[] = $line;
            },
            function (Options $options): Server {
                $this->options = $options;
                throw new \LogicException('not starting anything');
            },
        );
        try {
            $starter();
        } catch (\LogicException) {
        }
        self::assertNotNull($this->options);

        return $this->options;
    }

    public function testDefaultsToTheEmbeddedReleaseInMemory(): void
    {
        $options = $this->optionsFor([]);

        self::assertSame([null, false, null, null, 10.0], [$options->binary, $options->binaryFromPath, $options->dbPath, $options->schema, $options->startTimeout]);
    }

    public function testRelativePathsAreRelativeToTheConfigurationFile(): void
    {
        $options = $this->optionsFor(['binary' => 'bin/dynoxide', 'db-path' => 'var/test.db', 'schema' => '/abs/schema.json']);

        $sep = DIRECTORY_SEPARATOR;
        self::assertSame("/project/config{$sep}bin/dynoxide", $options->binary);
        self::assertSame("/project/config{$sep}var/test.db", $options->dbPath);
        self::assertSame('/abs/schema.json', $options->schema);
    }

    public function testAWindowsDrivePathIsAbsolute(): void
    {
        self::assertSame('C:\\tools\\dynoxide.exe', $this->optionsFor(['binary' => 'C:\\tools\\dynoxide.exe'])->binary);
    }

    public function testAParallelWorkerGetsItsOwnDatabaseFile(): void
    {
        putenv('PARATEST=1');
        putenv('TEST_TOKEN=3');

        $options = $this->optionsFor(['db-path' => '/data/test.db']);

        self::assertSame('/data/test.db-3', $options->dbPath);
        self::assertSame(['Dynoxide: parallel worker 3 uses the database /data/test.db-3'], $this->logged);
    }

    public function testATestTokenOutsideParaTestIsLeftAlone(): void
    {
        putenv('TEST_TOKEN=ghp_notaworker');

        self::assertSame('/data/test.db', $this->optionsFor(['db-path' => '/data/test.db'])->dbPath);
        self::assertSame([], $this->logged);
    }

    /** @return iterable<string, array{string|null}> */
    public static function missingTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'not a number' => ['abc/def'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingTokens')]
    public function testAParaTestWorkerWithoutANumberRefusesToShareTheDatabase(?string $token): void
    {
        putenv('PARATEST=1');
        if ($token !== null) {
            putenv("TEST_TOKEN={$token}");
        }
        $starter = Extension::starter(['db-path' => '/data/test.db'], '/project', start: static fn (): Server => throw new \LogicException('started'));

        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('--no-test-tokens');
        $starter();
    }

    public function testAWorkerWithoutADatabaseFileStaysInMemory(): void
    {
        putenv('PARATEST=1');
        putenv('TEST_TOKEN=3');

        self::assertNull($this->optionsFor([])->dbPath);
        self::assertSame([], $this->logged);
    }

    public function testReadsTheFlagAndTimeout(): void
    {
        $options = $this->optionsFor(['binary-from-path' => 'TRUE', 'timeout' => '2.5']);

        self::assertSame([true, 2.5], [$options->binaryFromPath, $options->startTimeout]);
        self::assertFalse($this->optionsFor(['binary-from-path' => 'no'])->binaryFromPath);
    }

    public function testRefusesAFlagThatIsNotTrueOrFalse(): void
    {
        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('binary-from-path parameter must be true or false, not "sometimes"');

        Extension::starter(['binary-from-path' => 'sometimes'], '/project');
    }

    public function testRefusesATimeoutThatIsNotANumber(): void
    {
        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('timeout parameter must be a number of seconds, not "soon"');

        Extension::starter(['timeout' => 'soon'], '/project');
    }
}
