<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Console;

use Dynoxide\Cache\Installer;
use Dynoxide\Console\InstallCommand;
use Dynoxide\Tests\Support\ServedRelease;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallCommandTest extends TestCase
{
    use TempDir;

    private const TARGET = 'x86_64-unknown-linux-musl';
    private const VERSION = '1.7.0';

    private ServedRelease $served;

    protected function setUp(): void
    {
        $this->served = new ServedRelease($this->tempDir());
    }

    protected function tearDown(): void
    {
        $this->served->stop();
    }

    /** Serves a release with one target's archive, or a damaged one. */
    private function release(?string $archive = null): void
    {
        $this->served->publish(self::VERSION, self::TARGET, corrupt: $archive);
    }

    /** @param array<string, string> $env */
    private function command(array $env = []): InstallCommand
    {
        return new InstallCommand(fn (): Installer => $this->served->installer($this->cacheRoot(), self::VERSION, self::TARGET), $env);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string} the exit status, stdout and stderr
     */
    private function invoke(array $args, ?InstallCommand $command = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = ($command ?? $this->command())->run($args, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private function downloads(): int
    {
        return $this->served->downloads();
    }

    private function cacheRoot(): string
    {
        return $this->tempDir() . DIRECTORY_SEPARATOR . 'cache';
    }

    private function expectedPath(): string
    {
        return $this->cacheRoot() . DIRECTORY_SEPARATOR . self::VERSION
            . DIRECTORY_SEPARATOR . self::TARGET . DIRECTORY_SEPARATOR . 'dynoxide';
    }

    public function testInstallDownloadsAndPrintsOnlyThePathToStdout(): void
    {
        $this->release();

        [$status, $out, $err] = $this->invoke(['install']);

        self::assertSame(0, $status, $err);
        self::assertSame($this->expectedPath() . "\n", $out);
        self::assertSame('Installed Dynoxide 1.7.0.' . PHP_EOL, $err);
        self::assertSame($this->served->binaryHash, hash_file('sha256', $this->expectedPath()));
        self::assertSame(1, $this->downloads());
    }

    public function testASecondInstallDoesNothing(): void
    {
        $this->release();
        $this->invoke(['install']);

        [$status, $out, $err] = $this->invoke(['install']);

        self::assertSame(0, $status, $err);
        self::assertSame($this->expectedPath() . "\n", $out);
        self::assertSame('Dynoxide 1.7.0 is already installed.' . PHP_EOL, $err);
        self::assertSame(1, $this->downloads());
    }

    public function testForceDownloadsAgain(): void
    {
        $this->release();
        $this->invoke(['install']);

        [$status, $out, $err] = $this->invoke(['install', '--force']);

        self::assertSame(0, $status, $err);
        self::assertSame($this->expectedPath() . "\n", $out);
        self::assertSame('Installed Dynoxide 1.7.0.' . PHP_EOL, $err);
        self::assertSame(2, $this->downloads());
    }

    public function testForceOnAnEmptyCacheInstalls(): void
    {
        $this->release();

        [$status, $out, $err] = $this->invoke(['install', '--force']);

        self::assertSame(0, $status, $err);
        self::assertSame($this->expectedPath() . "\n", $out);
        self::assertSame('Installed Dynoxide 1.7.0.' . PHP_EOL, $err);
        self::assertSame(1, $this->downloads());
    }

    public function testSaysWhenDynoxideBinaryWillBeRunInstead(): void
    {
        $this->release();

        [$status, $out, $err] = $this->invoke(['install'], $this->command(['DYNOXIDE_BINARY' => '/opt/dynoxide']));

        self::assertSame(0, $status, $err);
        self::assertSame($this->expectedPath() . "\n", $out);
        self::assertStringContainsString('DYNOXIDE_BINARY is set, so tests will run /opt/dynoxide instead, unless they set the binary option.', $err);
    }

    public function testAnEmptyDynoxideBinaryGetsNoNote(): void
    {
        $this->release();

        [$status, , $err] = $this->invoke(['install'], $this->command(['DYNOXIDE_BINARY' => '']));

        self::assertSame(0, $status, $err);
        self::assertSame('Installed Dynoxide 1.7.0.' . PHP_EOL, $err);
    }

    public function testAFailedInstallExitsNonZeroWithTheReasonAndNoPath(): void
    {
        $this->release('not an archive');

        [$status, $out, $err] = $this->invoke(['install']);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringStartsWith('dynoxide: ', $err);
        self::assertStringContainsString('dynoxide-' . self::TARGET . '.tar.gz', $err);
        self::assertFileDoesNotExist($this->expectedPath());
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function badArguments(): iterable
    {
        yield 'no command' => [[], 'Usage: dynoxide install'];
        yield 'unknown command' => [['start'], 'unknown command "start"'];
        yield 'a version, which only the embedded one can be' => [['install', '--version', '1.5.0'], 'install doesn\'t take "--version"'];
        yield 'a stray argument' => [['install', 'now'], 'install doesn\'t take "now"'];
    }

    /** @param list<string> $args */
    #[DataProvider('badArguments')]
    public function testBadArgumentsExitNonZeroWithUsageAndInstallNothing(array $args, string $message): void
    {
        $command = new InstallCommand(static fn (): Installer => self::fail('Nothing should be installed'));

        [$status, $out, $err] = $this->invoke($args, $command);

        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString($message, $err);
        self::assertStringContainsString(InstallCommand::USAGE, $err);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function helpArguments(): iterable
    {
        yield 'help' => [['help']];
        yield '--help' => [['--help']];
        yield '-h' => [['-h']];
        yield 'install --help' => [['install', '--help']];
        yield 'install --force -h' => [['install', '--force', '-h']];
    }

    /** @param list<string> $args */
    #[DataProvider('helpArguments')]
    public function testHelpPrintsUsageToStdout(array $args): void
    {
        $command = new InstallCommand(static fn (): Installer => self::fail('Nothing should be installed'));

        [$status, $out, $err] = $this->invoke($args, $command);

        self::assertSame(0, $status);
        self::assertSame(InstallCommand::USAGE . PHP_EOL, $out);
        self::assertSame('', $err);
    }

    /**
     * The script itself, run as Composer runs it from a checkout: it finds
     * the autoloader and passes its arguments and exit status through.
     */
    public function testTheScriptPassesArgumentsAndExitStatusThrough(): void
    {
        $script = dirname(__DIR__, 3) . '/bin/dynoxide';
        $run = static function (array $args): array {
            $process = proc_open(array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/dynoxide'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);

            return [proc_close($process), $out, $err];
        };
        self::assertFileExists($script);

        [$status, $out] = $run(['--help']);
        self::assertSame(0, $status);
        self::assertSame(InstallCommand::USAGE . "\n", str_replace("\r\n", "\n", (string) $out));

        [$status, , $err] = $run(['install', '--nope']);
        self::assertSame(1, $status);
        self::assertStringContainsString('install doesn\'t take "--nope"', (string) $err);
    }
}
