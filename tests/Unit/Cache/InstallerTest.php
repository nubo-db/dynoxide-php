<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Cache;

use Dynoxide\Cache\CacheDirectory;
use Dynoxide\Cache\Installer;
use Dynoxide\Download\Downloader;
use Dynoxide\Exception\DynoxideException;
use Dynoxide\Platform;
use Dynoxide\Release;
use Dynoxide\Tests\Support\Served;
use Dynoxide\Tests\Support\TarBuilder;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    use TempDir;

    private const TARGET = 'x86_64-unknown-linux-musl';
    private const VERSION = '1.7.0';

    private ?Served $server = null;
    private string $binary = '';
    private string $archiveHash = '';
    private string $binaryHash = '';

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    /**
     * Serves a release for one target. The archive and sha256sums.txt can be
     * swapped out to make it hostile.
     */
    private function release(string $version = self::VERSION, string $target = self::TARGET, ?string $archive = null, ?string $sums = null): void
    {
        $platform = Platform::fromTarget($target);
        $dir = $this->tempDir() . '/release-' . $version;
        @mkdir($dir);
        $this->binary = "binary for {$version} " . random_bytes(3000);
        $path = "{$dir}/" . $platform->archiveName();

        if ($archive !== null) {
            file_put_contents($path, $archive);
        } elseif ($platform->isWindows()) {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('dynoxide.exe', $this->binary);
            $zip->close();
        } else {
            (new TarBuilder(TarBuilder::GNU))->add('dynoxide', $this->binary)->writeGz($path);
        }
        $this->archiveHash = (string) hash_file('sha256', $path);
        $this->binaryHash = hash('sha256', $this->binary);
        file_put_contents("{$dir}/sha256sums.txt", $sums ?? "{$this->archiveHash}  {$platform->archiveName()}\n");

        $routes = [
            "/v{$version}/" . $platform->archiveName() => ['file' => $path],
            "/v{$version}/sha256sums.txt" => ['file' => "{$dir}/sha256sums.txt"],
        ];
        if ($this->server === null) {
            $this->server = Served::http($this->tempDir(), $routes);
        } else {
            $this->server->routes($routes);
        }
    }

    private function root(): string
    {
        return $this->tempDir() . '/cache';
    }

    private function installer(string $version = self::VERSION, string $target = self::TARGET, ?string $archiveHash = null, ?string $binaryHash = null): Installer
    {
        return new Installer(
            new CacheDirectory($this->root()),
            new Downloader([], 10.0, 5.0),
            Platform::fromTarget($target),
            $version,
            $archiveHash ?? $this->archiveHash,
            $binaryHash ?? $this->binaryHash,
            $this->server?->url() ?? 'https://example.invalid',
        );
    }

    private function downloads(): int
    {
        return count(array_filter($this->server?->log() ?? [], static fn (array $r): bool => str_ends_with($r['target'], '.tar.gz') || str_ends_with($r['target'], '.zip')));
    }

    /** @return list<string> everything in the version directory */
    private function leftovers(string $version = self::VERSION): array
    {
        $dir = $this->root() . '/' . $version;

        return is_dir($dir) ? self::listTree($dir) : [];
    }

    public function testInstallsAVerifiedBinary(): void
    {
        $this->release();

        $path = $this->installer()->install();

        self::assertSame($this->root() . '/1.7.0/' . self::TARGET . '/dynoxide', str_replace('\\', '/', $path));
        self::assertSame($this->binary, file_get_contents($path));
        self::assertSame($this->binaryHash . "\n", file_get_contents(dirname($path) . '/dynoxide.sha256'));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0755, fileperms($path) & 0777);
        }
        self::assertSame([self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256'], $this->leftovers());
    }

    public function testReusesAGoodInstallWithoutDownloading(): void
    {
        $this->release();
        $first = $this->installer()->install();

        $second = $this->installer()->install();

        self::assertSame($first, $second);
        self::assertSame(1, $this->downloads());
    }

    public function testInstalledReportsOnlyAGoodInstall(): void
    {
        $this->release();

        self::assertNull($this->installer()->installed());
        $path = $this->installer()->install();
        self::assertSame($path, $this->installer()->installed());
        self::assertSame(1, $this->downloads());
    }

    public function testForceReinstalls(): void
    {
        $this->release();
        $this->installer()->install();

        $path = $this->installer()->install(true);

        self::assertSame($this->binary, file_get_contents($path));
        self::assertSame(2, $this->downloads());
        self::assertCount(3, $this->leftovers());
    }

    public function testReinstallsABinaryThatDoesNotMatchItsChecksumFile(): void
    {
        $this->release();
        $path = $this->installer()->install();
        file_put_contents($path, 'corrupted');

        $this->installer()->install();

        self::assertSame($this->binary, file_get_contents($path));
        self::assertSame(2, $this->downloads());
        self::assertCount(3, $this->leftovers(), 'The bad install should be gone, not left aside');
    }

    public function testDoesNotUseAnInstallThatMatchesItsOwnChecksumButNotThisRelease(): void
    {
        // Another wrapper's install of a tampered binary: self-consistent, but wrong.
        $this->release();
        $path = $this->installer()->install();
        file_put_contents($path, 'tampered');
        file_put_contents(dirname($path) . '/dynoxide.sha256', hash('sha256', 'tampered') . "\n");

        self::assertNull($this->installer()->installed());
        $this->installer()->install();

        self::assertSame($this->binary, file_get_contents($path));
    }

    public function testRefusesAnInstallOthersCanWriteTo(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows relies on %LOCALAPPDATA% being per-user');
        }
        $this->release();
        $path = $this->installer()->install();
        chmod($path, 0775);

        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('group or other can write to it');
        $this->installer()->install();
    }

    public function testDoesNotFollowASymlinkedInstall(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlinks need extra privileges on Windows');
        }
        $this->release();
        $elsewhere = $this->tempDir() . '/elsewhere';
        mkdir($elsewhere, 0700);
        file_put_contents("{$elsewhere}/dynoxide", $this->binary);
        file_put_contents("{$elsewhere}/dynoxide.sha256", $this->binaryHash . "\n");
        mkdir($this->root() . '/1.7.0', 0700, true);
        chmod($this->root(), 0700);
        symlink($elsewhere, $this->root() . '/1.7.0/' . self::TARGET);

        $path = $this->installer()->install();

        self::assertFalse(is_link(dirname($path)));
        self::assertSame(1, $this->downloads());
        self::assertFileExists("{$elsewhere}/dynoxide", 'The symlink target must not be deleted');
    }

    public function testAnArchiveThatDoesNotMatchThisPackageFailsBeforeUnpacking(): void
    {
        $this->release();

        try {
            $this->installer(archiveHash: str_repeat('0', 64))->install();
            self::fail('Expected a failure');
        } catch (DynoxideException $e) {
            self::assertStringContainsString('checksum this package carries', $e->getMessage());
        }
        self::assertSame([], $this->leftovers());
    }

    public function testAnArchiveThatDoesNotMatchSha256sumsFails(): void
    {
        $this->release(sums: str_repeat('a', 64) . '  dynoxide-' . self::TARGET . ".tar.gz\n");

        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('sha256sums.txt');
        try {
            $this->installer()->install();
        } finally {
            self::assertSame([], $this->leftovers());
        }
    }

    public function testAnArchiveMissingFromSha256sumsFails(): void
    {
        $this->release(sums: str_repeat('a', 64) . "  dynoxide-aarch64-apple-darwin.tar.gz\n");

        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage('has no line');
        $this->installer()->install();
    }

    public function testABinaryThatDoesNotMatchThisPackageFails(): void
    {
        $this->release();

        try {
            $this->installer(binaryHash: str_repeat('0', 64))->install();
            self::fail('Expected a failure');
        } catch (DynoxideException $e) {
            self::assertStringContainsString('binary inside', $e->getMessage());
        }
        self::assertSame([], $this->leftovers());
    }

    public function testAHostileArchiveLeavesNothingBehind(): void
    {
        $hostile = gzencode((new TarBuilder())->add('dynoxide', 'bin')->add('../escape', 'x')->tar());
        $this->release(archive: $hostile);

        $this->expectException(DynoxideException::class);
        try {
            $this->installer()->install();
        } finally {
            self::assertSame([], $this->leftovers());
            self::assertFileDoesNotExist($this->root() . '/escape');
        }
    }

    public function testAFailedDownloadLeavesNothingBehind(): void
    {
        $this->release();
        $this->server?->routes([]);

        $this->expectException(DynoxideException::class);
        try {
            $this->installer()->install();
        } finally {
            self::assertSame([], $this->leftovers());
        }
    }

    public function testAnRcAndItsReleaseInstallSeparately(): void
    {
        $this->release('1.7.0-rc.1');
        $rc = $this->installer('1.7.0-rc.1')->install();
        $rcBinary = $this->binary;

        $this->release('1.7.0');
        $final = $this->installer('1.7.0')->install();

        self::assertNotSame(dirname($rc, 2), dirname($final, 2));
        self::assertSame($rcBinary, file_get_contents($rc));
        self::assertSame($this->binary, file_get_contents($final));
    }

    #[RequiresPhpExtension('zip')]
    public function testInstallsTheWindowsZip(): void
    {
        $this->release(target: 'x86_64-pc-windows-msvc');

        $path = $this->installer(target: 'x86_64-pc-windows-msvc')->install();

        self::assertStringEndsWith('dynoxide.exe', $path);
        self::assertSame($this->binary, file_get_contents($path));
    }

    public function testRefusesAnHttpMirrorOnAnotherHost(): void
    {
        $this->expectException(DynoxideException::class);
        new Installer(
            new CacheDirectory($this->root()),
            new Downloader([]),
            Platform::fromTarget(self::TARGET),
            '1.7.0',
            str_repeat('0', 64),
            str_repeat('0', 64),
            'http://example.com/mirror',
        );
    }

    public function testTheEmbeddedReleaseUsesTheMirrorVariable(): void
    {
        $installer = Installer::forEmbeddedRelease(
            ['DYNOXIDE_CACHE_DIR' => $this->root(), 'DYNOXIDE_DOWNLOAD_BASE_URL' => 'https://mirror.example.com/dx/'],
            Platform::fromTarget(self::TARGET),
        );

        self::assertStringContainsString(Release::VERSION, $installer->binaryPath());

        $this->expectException(DynoxideException::class);
        Installer::forEmbeddedRelease(['DYNOXIDE_CACHE_DIR' => $this->root(), 'DYNOXIDE_DOWNLOAD_BASE_URL' => 'http://mirror.example.com']);
    }

    public function testSeveralProcessesInstallingAtOnceAllGetTheSameBinary(): void
    {
        $this->release();
        $args = json_encode([
            'root' => $this->root(),
            'target' => self::TARGET,
            'version' => self::VERSION,
            'archive' => $this->archiveHash,
            'binary' => $this->binaryHash,
            'base' => $this->server?->url(),
        ]);

        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $err = $this->tempDir() . "/worker-{$i}.err";
            $proc = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/install-worker.php', (string) $args],
                [1 => ['pipe', 'w'], 2 => ['file', $err, 'w']],
                $pipes,
            );
            self::assertNotFalse($proc);
            $workers[] = [$proc, $pipes[1], $err];
        }

        $results = [];
        foreach ($workers as [$proc, $out, $err]) {
            $results[] = trim((string) stream_get_contents($out));
            fclose($out);
            self::assertSame(0, proc_close($proc), (string) file_get_contents($err));
        }

        $expected = $this->root() . DIRECTORY_SEPARATOR . self::VERSION . DIRECTORY_SEPARATOR . self::TARGET
            . DIRECTORY_SEPARATOR . 'dynoxide' . "\n" . $this->binaryHash;
        self::assertSame(array_fill(0, 6, $expected), $results);
    }

    public function testUsesAnotherProcessesInstallThatLandsFirst(): void
    {
        $this->release();
        $winner = $this->stageCompetingInstall($this->binary, $this->binaryHash);

        $path = $this->installer()->install();

        self::assertSame('theirs', file_get_contents(dirname($path) . '/marker'), 'The winner\'s install should be kept');
        self::assertSame($this->binary, file_get_contents($path));
        self::assertSame([self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256', self::TARGET . '/marker'], $this->leftovers());
        self::assertDirectoryDoesNotExist($winner);
    }

    public function testReplacesAnotherProcessesInstallThatDoesNotCheckOut(): void
    {
        $this->release();
        $this->stageCompetingInstall('bad binary', hash('sha256', 'bad binary'));

        $path = $this->installer()->install();

        self::assertSame($this->binary, file_get_contents($path));
        self::assertSame([self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256'], $this->leftovers());
    }

    /**
     * Prepares an install that the server moves into place while the
     * installer downloads sha256sums.txt, as a racing process would.
     */
    private function stageCompetingInstall(string $binary, string $hash): string
    {
        $staged = $this->tempDir() . '/winner';
        mkdir($staged, 0700);
        file_put_contents("{$staged}/dynoxide", $binary);
        chmod("{$staged}/dynoxide", 0755);
        file_put_contents("{$staged}/dynoxide.sha256", $hash . "\n");
        file_put_contents("{$staged}/marker", 'theirs');

        $target = $this->root() . '/' . self::VERSION . '/' . self::TARGET;
        $archiveName = Platform::fromTarget(self::TARGET)->archiveName();
        $this->server?->routes([
            '/v' . self::VERSION . '/' . $archiveName => ['file' => $this->tempDir() . '/release-' . self::VERSION . '/' . $archiveName],
            '/v' . self::VERSION . '/sha256sums.txt' => [
                'file' => $this->tempDir() . '/release-' . self::VERSION . '/sha256sums.txt',
                'rename' => [$staged, $target],
            ],
        ]);

        return $staged;
        self::assertSame([self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256'], $this->leftovers());
    }
}
