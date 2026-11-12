<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Binary\Source;
use Dynoxide\Dynoxide;
use Dynoxide\Exception\DynoxideException;
use Dynoxide\Options;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dynoxide::locate(), which finds the binary the way start() does without
 * starting it. The fakes here are shell scripts, run directly as start()
 * runs a binary, so the tests that run one are Unix-only.
 */
final class DynoxideTest extends TestCase
{
    use TempDir;

    private string|false $savedBinary = false;

    private string|false $savedPath = false;

    protected function setUp(): void
    {
        $this->savedBinary = getenv('DYNOXIDE_BINARY');
        $this->savedPath = getenv('PATH');
        putenv('DYNOXIDE_BINARY');
    }

    protected function tearDown(): void
    {
        putenv($this->savedBinary === false ? 'DYNOXIDE_BINARY' : "DYNOXIDE_BINARY={$this->savedBinary}");
        putenv($this->savedPath === false ? 'PATH' : "PATH={$this->savedPath}");
    }

    /** A script that prints $version the way `dynoxide --version` does. */
    private function fake(string $version, string $name = 'dynoxide'): string
    {
        $dir = $this->tempDir() . '/' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = "{$dir}/{$name}";
        file_put_contents($path, "#!/bin/sh\necho 'dynoxide {$version}'\n");
        chmod($path, 0755);

        return $path;
    }

    #[Group('unix')]
    public function testLocateUsesDynoxideBinary(): void
    {
        $path = $this->fake('1.7.0');
        putenv("DYNOXIDE_BINARY={$path}");

        $found = Dynoxide::locate();

        self::assertSame([$path, Source::Environment, '1.7.0', true], [$found->path, $found->source, $found->version, $found->launcherFlags]);
    }

    #[Group('unix')]
    public function testLocateUsesTheBinaryOptionBeforeDynoxideBinary(): void
    {
        putenv('DYNOXIDE_BINARY=' . $this->fake('1.8.0'));
        $path = $this->fake('1.7.0');

        $fromArray = Dynoxide::locate(['binary' => $path]);
        $fromOptions = Dynoxide::locate(new Options(binary: $path));

        foreach ([$fromArray, $fromOptions] as $found) {
            self::assertSame([$path, Source::Option, '1.7.0'], [$found->path, $found->source, $found->version]);
        }
    }

    #[Group('unix')]
    public function testLocateFindsDynoxideOnPathWhenAskedTo(): void
    {
        $path = $this->fake('1.7.0');
        putenv('PATH=' . dirname($path));

        $found = Dynoxide::locate(['binaryFromPath' => true]);

        self::assertSame([$path, Source::Path, '1.7.0', true], [$found->path, $found->source, $found->version, $found->launcherFlags]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function versions(): iterable
    {
        yield '1.6.0, before the launcher flags' => ['1.6.0', false];
        yield '1.7.0' => ['1.7.0', true];
        yield 'a 1.7.0 release candidate' => ['1.7.0-rc.1', true];
    }

    #[Group('unix')]
    #[DataProvider('versions')]
    public function testReportsWhetherTheBinaryTakesTheLauncherFlags(string $version, bool $flags): void
    {
        $found = Dynoxide::locate(['binary' => $this->fake($version)]);

        self::assertSame([$version, $flags], [$found->version, $found->launcherFlags]);
    }

    public function testAMissingBinaryFailsWithTheErrorStartGives(): void
    {
        $missing = $this->tempDir() . DIRECTORY_SEPARATOR . 'no-such-dynoxide';

        $located = self::failure(static fn () => Dynoxide::locate(['binary' => $missing]));
        $started = self::failure(static fn () => Dynoxide::start(['binary' => $missing]));

        self::assertSame([get_class($started), $started->getMessage()], [get_class($located), $located->getMessage()]);
        self::assertStringContainsString("{$missing}, which doesn't exist", $located->getMessage());
    }

    public function testRefusesBothTheBinaryOptionAndBinaryFromPath(): void
    {
        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('not both');

        Dynoxide::locate(['binary' => '/usr/local/bin/dynoxide', 'binaryFromPath' => true]);
    }

    private static function failure(\Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('expected it to fail');
    }
}
