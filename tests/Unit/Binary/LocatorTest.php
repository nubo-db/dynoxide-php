<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Binary;

use Dynoxide\Binary\LocatedBinary;
use Dynoxide\Binary\Locator;
use Dynoxide\Binary\Source;
use Dynoxide\Cache\CacheDirectory;
use Dynoxide\Cache\Installer;
use Dynoxide\Download\Downloader;
use Dynoxide\Exception\BinaryException;
use Dynoxide\Platform;
use Dynoxide\Tests\Support\FakeBinary;
use Dynoxide\Tests\Support\Served;
use Dynoxide\Tests\Support\TarBuilder;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class LocatorTest extends TestCase
{
    use TempDir;

    private const TARGET = 'x86_64-unknown-linux-musl';
    private const VERSION = '1.7.0';
    private const CACHED = 'the cached binary';

    private ?Served $server = null;
    private int $installers = 0;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /**
     * A locator whose cache holds nothing unless the test puts it there, and
     * whose fake binaries run through PHP.
     *
     * @param array<string, string> $env
     */
    private function locator(array $env = [], string $osFamily = PHP_OS_FAMILY, string $version = self::VERSION, float $timeout = 10.0): Locator
    {
        $locator = new Locator($env, function () use ($version): Installer {
            $this->installers++;

            return new Installer(
                new CacheDirectory($this->tempDir() . '/cache'),
                new Downloader([], 10.0, 5.0),
                Platform::fromTarget(self::TARGET),
                $version,
                (string) hash_file('sha256', $this->archive()),
                hash('sha256', self::CACHED),
                $this->server?->url() ?? 'https://example.invalid',
            );
        }, $osFamily, $timeout);
        (function (): void {
            $this->command = FakeBinary::command(...);
        })->call($locator);

        return $locator;
    }

    private function archive(): string
    {
        $path = $this->tempDir() . '/dynoxide-' . self::TARGET . '.tar.gz';
        if (!is_file($path)) {
            (new TarBuilder(TarBuilder::GNU))->add('dynoxide', self::CACHED)->writeGz($path);
        }

        return $path;
    }

    private function serveRelease(): void
    {
        $sums = $this->tempDir() . '/sha256sums.txt';
        file_put_contents($sums, hash_file('sha256', $this->archive()) . '  ' . basename($this->archive()) . "\n");
        $this->server = Served::http($this->tempDir(), [
            '/v' . self::VERSION . '/' . basename($this->archive()) => ['file' => $this->archive()],
            '/v' . self::VERSION . '/sha256sums.txt' => ['file' => $sums],
        ]);
    }

    private function cachedPath(string $version = self::VERSION): string
    {
        return $this->tempDir() . '/cache/' . $version . '/' . self::TARGET . '/dynoxide';
    }

    private function assertFound(LocatedBinary $found, string $path, Source $source, string $version, bool $flags): void
    {
        self::assertSame(
            [str_replace('\\', '/', $path), $source, $version, $flags],
            [str_replace('\\', '/', $found->path), $found->source, $found->version, $found->launcherFlags],
        );
    }

    public function testUsesTheBinaryOptionFirst(): void
    {
        $option = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");
        $env = FakeBinary::printing($this->tempDir(), "dynoxide 1.8.0\n");

        $found = $this->locator(['DYNOXIDE_BINARY' => $env->path])->locate($option->path);

        $this->assertFound($found, $option->path, Source::Option, '1.7.0', true);
        self::assertSame(0, $this->installers);
    }

    public function testUsesDynoxideBinaryWhenNoPathIsPassed(): void
    {
        $env = FakeBinary::printing($this->tempDir(), "dynoxide 1.8.0\n");

        $found = $this->locator(['DYNOXIDE_BINARY' => $env->path])->locate();

        $this->assertFound($found, $env->path, Source::Environment, '1.8.0', true);
        self::assertSame(0, $this->installers, 'the cache is never looked at, so an unsupported platform still works');
    }

    public function testUsesTheCacheWhenNothingElseIsSet(): void
    {
        $this->serveRelease();
        $this->locator()->locate();
        $this->server?->stop();
        $this->server = null;

        $found = $this->locator(['DYNOXIDE_BINARY' => ''])->locate();

        $this->assertFound($found, $this->cachedPath(), Source::Cache, self::VERSION, true);
    }

    public function testDownloadsWhenTheCacheIsEmpty(): void
    {
        $this->serveRelease();

        $found = $this->locator()->locate();

        $this->assertFound($found, $this->cachedPath(), Source::Download, self::VERSION, true);
        self::assertSame(self::CACHED, file_get_contents($found->path));
    }

    public function testACachedOrDownloadedVersionBefore170IsStartedWithoutTheLauncherFlags(): void
    {
        $path = $this->tempDir() . '/cache/1.6.0/' . self::TARGET;
        (new CacheDirectory($this->tempDir() . '/cache'))->prepare('1.6.0', self::TARGET);
        file_put_contents("{$path}/dynoxide", self::CACHED);
        // As an install leaves it, whatever the umask: the cache refuses group-writable files.
        chmod("{$path}/dynoxide", 0755);
        file_put_contents("{$path}/dynoxide.sha256", hash('sha256', self::CACHED) . "\n");

        $found = $this->locator(version: '1.6.0')->locate();

        $this->assertFound($found, $this->cachedPath('1.6.0'), Source::Cache, '1.6.0', false);
    }

    public function testAMissingDynoxideBinaryFailsRatherThanFallingThrough(): void
    {
        $missing = $this->tempDir() . '/nowhere/dynoxide';
        try {
            $this->locator(['DYNOXIDE_BINARY' => $missing])->locate();
            self::fail('expected the missing binary to be refused');
        } catch (BinaryException $e) {
            self::assertSame("DYNOXIDE_BINARY points at {$missing}, which doesn't exist.", $e->getMessage());
        }
        self::assertSame(0, $this->installers);
    }

    public function testAMissingBinaryOptionFails(): void
    {
        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('The binary option points at ');

        $this->locator()->locate($this->tempDir() . '/nowhere/dynoxide');
    }

    public function testAnEmptyBinaryOptionFails(): void
    {
        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('The binary option is empty.');

        $this->locator()->locate('');
    }

    public function testADirectoryIsRefused(): void
    {
        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage("which isn't a file.");

        $this->locator()->locate($this->tempDir());
    }

    public function testARelativePathIsMadeAbsolute(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");
        $cwd = (string) getcwd();
        chdir(dirname($fake->path));
        try {
            $found = $this->locator()->locate('dynoxide');
        } finally {
            chdir($cwd);
        }

        $this->assertFound($found, $fake->path, Source::Option, '1.7.0', true);
    }

    #[Group('unix')]
    public function testARelativePathFailsWhenTheWorkingDirectoryIsGone(): void
    {
        $gone = $this->tempDir() . '/gone';
        mkdir($gone);
        $cwd = (string) getcwd();
        chdir($gone);
        rmdir($gone);
        try {
            $this->expectException(BinaryException::class);
            $this->expectExceptionMessage("The binary option points at tmp, a relative path, and the current directory can't be read.");

            $this->locator()->locate('tmp');
        } finally {
            chdir($cwd);
        }
    }

    public function testRefusesAWindowsDriveRelativePath(): void
    {
        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage("DYNOXIDE_BINARY points at C:bin\\dynoxide.exe, which is relative to that drive's current directory.");

        $this->locator(['DYNOXIDE_BINARY' => 'C:bin\\dynoxide.exe'], 'Windows')->locate();
    }

    public function testA16BinaryUsesTheFallbackAndARcCountsAsItsRelease(): void
    {
        $old = FakeBinary::printing($this->tempDir(), "dynoxide 1.6.2\n");
        $rc = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0-rc.1\n");

        self::assertFalse($this->locator()->locate($old->path)->launcherFlags);
        self::assertTrue($this->locator()->locate($rc->path)->launcherFlags);
    }

    public function testRefusesABinaryTooOldNamingBothVersions(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.5.0\n");

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage(
            "{$fake->path} is Dynoxide 1.5.0, and this package needs 1.6.0 or later. Pass a newer one, or leave the binary option out",
        );

        $this->locator()->locate($fake->path);
    }

    public function testTheAdviceForAnOldBinaryOnPathIsAboutPath(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.5.0\n", PHP_OS_FAMILY === 'Windows' ? 'dynoxide.exe' : 'dynoxide');

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('Upgrade the dynoxide on PATH, or turn binaryFromPath off');

        $this->locator(['PATH' => dirname($fake->path)])->locate(fromPath: true);
    }

    public function testTheAdviceForAnOldDynoxideBinaryIsAboutTheVariable(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.5.0\n");

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('Point DYNOXIDE_BINARY at a newer one, or unset it');

        $this->locator(['DYNOXIDE_BINARY' => $fake->path])->locate();
    }

    /**
     * A version manager's shim decides what to run from the name it was
     * called by, so the link is run as given, not what it points at.
     */
    #[Group('unix')]
    public function testRunsALinkAsGivenRatherThanWhatItPointsAt(): void
    {
        $shim = FakeBinary::script($this->tempDir(), 'echo basename($argv[0]) === "dynoxide" ? "dynoxide 1.7.0\n" : "manager 2.0\n";', 'manager');
        $link = dirname($shim->path) . '/dynoxide';
        symlink($shim->path, $link);

        $found = $this->locator(['DYNOXIDE_BINARY' => $link])->locate();

        $this->assertFound($found, $link, Source::Environment, '1.7.0', true);
    }

    public function testReadsDynoxideBinaryInAnyCaseOnWindows(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");

        $found = $this->locator(['Dynoxide_Binary' => $fake->path], 'Windows')->locate();

        self::assertSame(Source::Environment, $found->source);
    }

    public function testRefusesSomethingThatIsNotDynoxide(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "garbage\n");

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('not "dynoxide <version>"');

        $this->locator(['DYNOXIDE_BINARY' => $fake->path])->locate();
    }

    public function testFindsDynoxideOnPathWhenAskedTo(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n", PHP_OS_FAMILY === 'Windows' ? 'dynoxide.exe' : 'dynoxide');
        $other = $this->tempDir() . '/empty';
        mkdir($other);
        $path = implode(PATH_SEPARATOR, ['relative/dir', '', $other, dirname($fake->path)]);

        $found = $this->locator(['PATH' => $path, 'DYNOXIDE_BINARY' => '/not/used'])->locate(fromPath: true);

        $this->assertFound($found, $fake->path, Source::Path, '1.7.0', true);
    }

    public function testIgnoresPathUnlessAskedTo(): void
    {
        $this->serveRelease();
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");

        $found = $this->locator(['PATH' => dirname($fake->path)])->locate();

        self::assertSame(Source::Download, $found->source);
    }

    public function testSkipsARelativePathEntry(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");
        $cwd = (string) getcwd();
        chdir(dirname($fake->path, 2));
        try {
            $this->expectException(BinaryException::class);
            $this->expectExceptionMessage("binaryFromPath is on, but there's no dynoxide on PATH.");

            $this->locator(['PATH' => basename(dirname($fake->path))], 'Linux')->locate(fromPath: true);
        } finally {
            chdir($cwd);
        }
    }

    public function testFindsDynoxideExeOnAWindowsPath(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n", 'dynoxide.exe');
        $decoy = FakeBinary::printing($this->tempDir(), "dynoxide 9.9.9\n");

        $found = $this->locator(['Path' => dirname($decoy->path) . ';"' . dirname($fake->path) . '"'], 'Windows')->locate(fromPath: true);

        self::assertSame([Source::Path, '1.7.0'], [$found->source, $found->version]);
        self::assertStringEndsWith('dynoxide.exe', $found->path);
    }

    public function testRefusesBothTheBinaryOptionAndPath(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage('Pass either the binary option or binaryFromPath, not both.');

        $this->locator()->locate($fake->path, true);
    }

    #[Group('unix')]
    public function testRefusesABinaryThatIsNotExecutable(): void
    {
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");
        chmod($fake->path, 0644);

        $this->expectException(BinaryException::class);
        $this->expectExceptionMessage("which isn't executable. Run chmod +x on it.");

        $this->locator()->locate($fake->path);
    }

    #[Group('unix')]
    public function testSkipsAPathEntryThatIsNotExecutable(): void
    {
        $plain = FakeBinary::printing($this->tempDir(), "dynoxide 9.9.9\n");
        chmod($plain->path, 0644);
        $fake = FakeBinary::printing($this->tempDir(), "dynoxide 1.7.0\n");

        $found = $this->locator(['PATH' => dirname($plain->path) . ':' . dirname($fake->path)])->locate(fromPath: true);

        self::assertSame('1.7.0', $found->version);
    }

    public function testDescribesWhereTheBinaryCameFrom(): void
    {
        $user = new LocatedBinary('/opt/dynoxide', Source::Environment, '1.7.0', true);
        $old = new LocatedBinary('/c/dynoxide', Source::Cache, '1.6.0', false);

        self::assertSame('Dynoxide 1.7.0 at /opt/dynoxide, from DYNOXIDE_BINARY (not checksum-verified)', $user->describe());
        self::assertSame('Dynoxide 1.6.0 at /c/dynoxide, from the cache, started without the launcher flags it predates', $old->describe());
    }
}
