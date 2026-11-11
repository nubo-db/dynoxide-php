<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Binary;

use Dynoxide\Binary\VersionCheck;
use Dynoxide\Exception\BinaryException;
use Dynoxide\Tests\Support\FakeBinary;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class VersionCheckTest extends TestCase
{
    use TempDir;

    /** @return iterable<string, array{string, ?string}> */
    public static function outputs(): iterable
    {
        yield 'a release' => ["dynoxide 1.7.0\n", '1.7.0'];
        yield 'a release candidate' => ["dynoxide 1.7.0-rc.1\n", '1.7.0-rc.1'];
        yield 'build metadata' => ["dynoxide 1.7.0+abc.1\n", '1.7.0+abc.1'];
        yield 'Windows line ending' => ["dynoxide 1.7.0\r\n", '1.7.0'];
        yield 'no newline' => ['dynoxide 1.7.0', '1.7.0'];
        yield 'later lines are ignored' => ["dynoxide 1.7.0\nsomething else\n", '1.7.0'];
        yield 'another program' => ["curl 8.5.0\n", null];
        yield 'a v prefix' => ["dynoxide v1.7.0\n", null];
        yield 'two parts' => ["dynoxide 1.7\n", null];
        yield 'trailing words' => ["dynoxide 1.7.0 (abc)\n", null];
        yield 'nothing' => ['', null];
    }

    #[DataProvider('outputs')]
    public function testParsesTheVersionLine(string $output, ?string $expected): void
    {
        self::assertSame($expected, VersionCheck::parse($output));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function startableVersions(): iterable
    {
        yield '1.7.0' => ['1.7.0', true];
        yield 'a 1.7.0 release candidate counts as 1.7.0' => ['1.7.0-rc.1', true];
        yield 'later' => ['1.10.2', true];
        yield 'a new major' => ['2.0.0', true];
        yield '1.6.0 uses the fallback' => ['1.6.0', false];
        yield '1.6.9 uses the fallback' => ['1.6.9', false];
        yield 'a 1.6.0 release candidate counts as 1.6.0' => ['1.6.0-rc.2', false];
    }

    #[DataProvider('startableVersions')]
    public function testDecidesWhetherTheLauncherFlagsAreThere(string $version, bool $flags): void
    {
        self::assertSame($flags, VersionCheck::supportsLauncherFlags($version, '/bin/dynoxide', 'Get a newer one.'));
    }

    public function testRefusesAVersionTooOldToStartWithBothVersionsInTheMessage(): void
    {
        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('/bin/dynoxide is Dynoxide 1.5.3, and this package needs 1.6.0 or later. Get a newer one.');

        VersionCheck::supportsLauncherFlags('1.5.3', '/bin/dynoxide', 'Get a newer one.');
    }

    public function testRunsTheBinaryAndReadsItsVersion(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0-rc.1\n");

        self::assertSame('1.7.0-rc.1', VersionCheck::run($fake->command, $fake->path));
    }

    public function testPassesOnlyTheVersionFlag(): void
    {
        $fake = FakeBinary::script($this->tempDir(), 'echo "dynoxide 1.7.0\n"; exit($argv === [$argv[0], "--version"] ? 0 : 3);');

        self::assertSame('1.7.0', VersionCheck::run($fake->command, $fake->path));
    }

    public function testABinaryWaitingOnStdinGetsEndOfFile(): void
    {
        $fake = FakeBinary::script($this->tempDir(), 'stream_get_contents(STDIN); echo "dynoxide 1.7.0\n";');

        self::assertSame('1.7.0', VersionCheck::run($fake->command, $fake->path, 5.0));
    }

    public function testRefusesOutputThatIsNotAVersion(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "usage: something else\n");

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('--version printed "usage: something else", not "dynoxide <version>"');

        VersionCheck::run($fake->command, $fake->path);
    }

    public function testRefusesAFailingBinaryAndShowsWhatItSaid(): void
    {
        $fake = FakeBinary::script($this->tempDir(), 'fwrite(STDERR, "unknown flag\n"); exit(2);');

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('--version failed with exit status 2. It said: "unknown flag"');

        VersionCheck::run($fake->command, $fake->path);
    }

    public function testGivesUpOnABinaryThatNeverAnswers(): void
    {
        $fake = FakeBinary::script($this->tempDir(), 'sleep(30);');

        $started = microtime(true);
        try {
            VersionCheck::run($fake->command, $fake->path, 0.5);
            self::fail('expected a timeout');
        } catch (BinaryException $e) {
            self::assertStringContainsString("didn't finish within 0.5 seconds", $e->getMessage());
        }
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testATimeoutAlsoStopsWhatAWrapperStarted(): void
    {
        $pidFile = $this->tempDir() . DIRECTORY_SEPARATOR . 'grandchild.pid';
        $fake = FakeBinary::forking($this->tempDir(), $pidFile);

        try {
            try {
                VersionCheck::run($fake->command, $fake->path, 2.0);
                self::fail('expected a timeout');
            } catch (BinaryException $e) {
                self::assertStringContainsString("didn't finish within 2 seconds", $e->getMessage());
            }
            $pid = (int) @file_get_contents($pidFile);
            self::assertGreaterThan(0, $pid, 'the wrapper never started its child');
            $identified = FakeBinary::identify($pid);
            $deadline = microtime(true) + 3;
            while (FakeBinary::alive($pid) && microtime(true) < $deadline) {
                usleep(50_000);
            }
            self::assertFalse(FakeBinary::alive($pid), "the wrapper's child, {$pid}, is still running");
        } finally {
            FakeBinary::killIdentified($identified ?? []);
        }
    }

    /**
     * PHP 8.3 and later fail to start it; earlier versions start a child
     * that exits 127. Either way it's refused with the path.
     */
    public function testRefusesABinaryThatIsNotThere(): void
    {
        $path = $this->tempDir() . DIRECTORY_SEPARATOR . 'missing' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        $this->expectCannotRun($path);

        VersionCheck::run([$path], $path);
    }

    #[Group('unix')]
    public function testRunsARealExecutableWithoutAnInterpreter(): void
    {
        $path = $this->tempDir() . '/dynoxide';
        file_put_contents($path, "#!/bin/sh\necho 'dynoxide 1.6.0'\n");
        chmod($path, 0755);

        self::assertSame('1.6.0', VersionCheck::run([$path], $path));
    }

    #[Group('unix')]
    public function testRefusesAFileTheSystemCannotExecute(): void
    {
        $path = $this->tempDir() . '/dynoxide';
        file_put_contents($path, "\x00\x01\x02 not a program");
        chmod($path, 0755);

        $this->expectCannotRun($path);

        VersionCheck::run([$path], $path);
    }

    #[Group('unix')]
    public function testRefusesABinaryKilledByASignal(): void
    {
        $path = $this->tempDir() . '/dynoxide';
        file_put_contents($path, "#!/bin/sh\nkill -9 \$\$\n");
        chmod($path, 0755);

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage("{$path} --version was killed by signal 9.");

        VersionCheck::run([$path], $path);
    }

    /**
     * Where PHP can tell at once, it fails to start the binary; otherwise the
     * child exits 127, or 126 if /bin/sh was tried. Which happens depends on
     * the PHP version and platform.
     */
    private function expectCannotRun(string $path): void
    {
        $this->expectException(BinaryException::class);
        $this->expectExceptionMessageMatches(
            '/^(Couldn\'t run ' . preg_quote($path, '/') . ': |' . preg_quote($path, '/')
            . ' --version failed with exit status 12[67], which usually means it couldn\'t be executed at all)/',
        );
    }
}
