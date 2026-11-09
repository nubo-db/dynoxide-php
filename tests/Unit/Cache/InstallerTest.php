<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Cache;

use Dynoxide\Cache\CacheDirectory;
use Dynoxide\Cache\Installer;
use Dynoxide\Download\Downloader;
use Dynoxide\Exception\ArchiveException;
use Dynoxide\Exception\CacheException;
use Dynoxide\Exception\DynoxideException;
use Dynoxide\Platform;
use Dynoxide\Release;
use Dynoxide\Tests\Support\Served;
use Dynoxide\Tests\Support\TarBuilder;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
    private function release(string $version = self::VERSION, string $target = self::TARGET, ?string $archive = null, ?string $sums = null, ?string $binary = null): void
    {
        $platform = Platform::fromTarget($target);
        $dir = $this->tempDir() . '/release-' . $version;
        @mkdir($dir);
        $this->binary = $binary ?? "binary for {$version} " . random_bytes(3000);
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

    #[Group('unix')]
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

    #[Group('unix')]
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
            self::assertStringContainsString("got {$this->binaryHash}", $e->getMessage());
            self::assertStringContainsString('DYNOXIDE_DOWNLOAD_BASE_URL', $e->getMessage());
        }
        self::assertSame([], $this->leftovers());
    }

    public function testAHostileArchiveLeavesNothingBehind(): void
    {
        // The binary in it is the right one, so only the archive reader can refuse it.
        $binary = 'the real binary ' . random_bytes(100);
        $hostile = (string) gzencode((new TarBuilder())->add('dynoxide', $binary)->add('../escape', 'x')->tar());
        $this->release(archive: $hostile, binary: $binary);

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('more than one member');
        try {
            $this->installer()->install();
        } finally {
            self::assertSame([], $this->leftovers());
            self::assertFileDoesNotExist($this->root() . '/escape');
        }
    }

    public function testAFailedDownloadSaysWhatToTryAndLeavesNothingBehind(): void
    {
        $this->release();
        $this->server?->routes([]);

        try {
            $this->installer()->install();
            self::fail('Expected a failure');
        } catch (DynoxideException $e) {
            self::assertStringContainsString('HTTP 404', $e->getMessage());
            foreach (['https_proxy', 'DYNOXIDE_DOWNLOAD_BASE_URL', 'DYNOXIDE_BINARY'] as $hint) {
                self::assertStringContainsString($hint, $e->getMessage());
            }
        }
        self::assertSame([], $this->leftovers());
    }

    public function testAMalformedSha256sumsFails(): void
    {
        $this->release();
        $platform = Platform::fromTarget(self::TARGET);
        $this->release(sums: "{$this->archiveHash}  {$platform->archiveName()}
not a line
", binary: $this->binary);

        $this->expectException(DynoxideException::class);
        $this->expectExceptionMessage("can't be read: not a line");
        $this->installer()->install();
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
        self::assertSame([self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256'], $this->leftovers());
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

    /** @return iterable<string, array{\Closure(string): string}> */
    public static function otherChecksumFiles(): iterable
    {
        yield 'no newline' => [static fn (string $hash): string => $hash];
        yield 'upper case with CRLF' => [static fn (string $hash): string => strtoupper($hash) . "\r\n"];
        yield 'sha256sum format' => [static fn (string $hash): string => "{$hash}  dynoxide\n"];
    }

    /** @param \Closure(string): string $format */
    #[DataProvider('otherChecksumFiles')]
    public function testUsesAGoodInstallWhoseChecksumFileAnotherWrapperWrote(\Closure $format): void
    {
        $this->release();
        $path = $this->installer()->install();
        file_put_contents(dirname($path) . '/dynoxide.sha256', $format($this->binaryHash));

        self::assertSame($path, $this->installer()->installed());
        self::assertSame($path, $this->installer()->install());
        self::assertSame(1, $this->downloads());
    }

    /** @return iterable<string, array{?string}> */
    public static function badChecksumFiles(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'garbage' => ["not a hash\n"];
        yield 'another hash' => [str_repeat('a', 64) . "\n"];
    }

    #[DataProvider('badChecksumFiles')]
    public function testReinstallsAGoodBinaryWithABadChecksumFile(?string $contents): void
    {
        $this->release();
        $path = $this->installer()->install();
        $sums = dirname($path) . '/dynoxide.sha256';
        $contents === null ? unlink($sums) : file_put_contents($sums, $contents);

        self::assertNull($this->installer()->installed());
        $this->installer()->install();

        self::assertSame($this->binaryHash . "\n", file_get_contents($sums));
        self::assertSame(2, $this->downloads());
    }

    #[Group('unix')]
    public function testReinstallsASymlinkedBinary(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlinks need extra privileges on Windows');
        }
        $this->release();
        $path = $this->installer()->install();
        $elsewhere = $this->tempDir() . '/elsewhere-binary';
        rename($path, $elsewhere);
        symlink($elsewhere, $path);

        self::assertNull($this->installer()->installed());
        $this->installer()->install();

        self::assertFalse(is_link($path));
        self::assertSame(2, $this->downloads());
        self::assertFileExists($elsewhere, 'The symlink target must not be deleted');
    }

    public function testInstalledChangesNothingOnDisk(): void
    {
        $this->release();

        self::assertNull($this->installer()->installed());

        self::assertDirectoryDoesNotExist($this->root());
    }

    #[Group('unix')]
    public function testInstalledWorksOnAReadOnlyCache(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows relies on %LOCALAPPDATA% being per-user');
        }
        $this->release();
        $path = $this->installer()->install();
        $versionDir = $this->root() . '/' . self::VERSION;
        chmod($versionDir, 0500);
        chmod($this->root(), 0500);

        try {
            self::assertSame($path, $this->installer()->installed());
        } finally {
            chmod($this->root(), 0700);
            chmod($versionDir, 0700);
        }
    }

    public function testClearsAwayWhatAKilledInstallLeftBehind(): void
    {
        $this->release();
        $versionDir = $this->root() . '/' . self::VERSION;
        mkdir($versionDir, 0700, true);
        $old = ['.tmp-0123456789abcdef', '.tmp-0123456789abcdef-' . Platform::fromTarget(self::TARGET)->archiveName(), '.stale-fedcba9876543210'];
        mkdir("{$versionDir}/{$old[0]}");
        file_put_contents("{$versionDir}/{$old[0]}/dynoxide", 'half');
        file_put_contents("{$versionDir}/{$old[1]}", 'half an archive');
        mkdir("{$versionDir}/{$old[2]}");
        foreach ($old as $entry) {
            touch("{$versionDir}/{$entry}", time() - 7200);
        }
        // A recent one may be another install that's still running, and
        // anything else isn't ours to remove.
        mkdir("{$versionDir}/.tmp-aaaaaaaaaaaaaaaa");
        file_put_contents("{$versionDir}/keep-me", 'not ours');
        touch("{$versionDir}/keep-me", time() - 7200);

        $this->installer()->install();

        self::assertSame(
            ['.tmp-aaaaaaaaaaaaaaaa', 'keep-me', self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256'],
            $this->leftovers(),
        );
    }

    #[Group('unix')]
    public function testReplacingALinkedInstallTouchesNothingItPointsAt(): void
    {
        $this->release();
        $versionDir = $this->root() . '/' . self::VERSION;
        mkdir($versionDir, 0700, true);
        $outside = $this->tempDir() . '/not-there';
        symlink($outside, $versionDir . '/' . self::TARGET);

        $this->installer()->install(force: true);

        self::assertFileDoesNotExist($outside);
        self::assertFalse(is_link($versionDir . '/' . self::TARGET));
    }

    #[Group('unix')]
    public function testTheSweepRemovesALinkWithoutFollowingIt(): void
    {
        $this->release();
        $versionDir = $this->root() . '/' . self::VERSION;
        mkdir($versionDir, 0700, true);
        $outside = $this->tempDir() . '/outside';
        mkdir($outside);
        file_put_contents("{$outside}/precious", 'keep');
        symlink($outside, "{$versionDir}/.stale-0123456789abcdef");
        // lstat sees the link's own time; backdate it without touching the target.
        exec('touch -h -t 202001010000 ' . escapeshellarg("{$versionDir}/.stale-0123456789abcdef"), result_code: $code);
        self::assertSame(0, $code);

        $this->installer()->install();

        self::assertFalse(is_link("{$versionDir}/.stale-0123456789abcdef"));
        self::assertSame('keep', file_get_contents("{$outside}/precious"));
    }

    /** @param \Closure(string, string): bool $rename */
    private function withRename(Installer $installer, \Closure $rename): Installer
    {
        (function () use ($rename): void {
            $this->rename = $rename;
            $this->backoffMicroseconds = 1_000;
        })->call($installer);

        return $installer;
    }

    public function testRetriesARenameThatFailsForAMoment(): void
    {
        // As when a virus scanner on Windows holds the new .exe open.
        $this->release();
        $calls = 0;
        $installer = $this->withRename($this->installer(), static function (string $from, string $to) use (&$calls): bool {
            return ++$calls > 2 && rename($from, $to);
        });

        $path = $installer->install();

        self::assertSame(3, $calls);
        self::assertSame($this->binary, file_get_contents($path));
        self::assertSame([self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256'], $this->leftovers());
    }

    public function testGivesUpOnARenameThatKeepsFailing(): void
    {
        $this->release();
        $calls = 0;
        $installer = $this->withRename($this->installer(), static function () use (&$calls): bool {
            $calls++;
            trigger_error('rename(a,b): Access is denied', E_USER_WARNING);

            return false;
        });

        try {
            @$installer->install();
            self::fail('Expected a failure');
        } catch (CacheException $e) {
            self::assertStringContainsString(self::TARGET, $e->getMessage());
            self::assertStringContainsString('Access is denied', $e->getMessage());
            self::assertStringContainsString('DYNOXIDE_CACHE_DIR', $e->getMessage());
        }
        self::assertSame(6, $calls);
        self::assertSame([], $this->leftovers());
    }

    public function testABadInstallThatCannotBeMovedAsideFails(): void
    {
        $this->release();
        $path = $this->installer()->install();
        file_put_contents($path, 'corrupted');
        $installer = $this->withRename($this->installer(), static fn (string $from, string $to): bool => !str_contains($to, '.stale-') && rename($from, $to));

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('aside to replace it');
        $installer->install();
    }

    public function testPutsBackAGoodInstallThatLandsWhileMovingABadOneAside(): void
    {
        $this->release();
        $path = $this->installer()->install();
        file_put_contents($path, 'corrupted');
        $dir = dirname($path);
        $binary = $this->binary;
        $hash = $this->binaryHash;
        // Just before the bad install is moved aside, another process
        // replaces it with a good one.
        $installer = $this->withRename($this->installer(), static function (string $from, string $to) use ($dir, $binary, $hash): bool {
            if ($from === $dir && str_contains($to, '.stale-')) {
                file_put_contents("{$dir}/dynoxide", $binary);
                file_put_contents("{$dir}/dynoxide.sha256", $hash . "\n");
                file_put_contents("{$dir}/marker", 'theirs');
            }

            return rename($from, $to);
        });

        $installer->install();

        self::assertSame('theirs', file_get_contents("{$dir}/marker"), 'The other process\'s install should be kept');
        self::assertSame([self::TARGET, self::TARGET . '/dynoxide', self::TARGET . '/dynoxide.sha256', self::TARGET . '/marker'], $this->leftovers());
    }

    public function testAnotherProcessesSweepDoesNotTakeAnInstallBeingPutBack(): void
    {
        $this->release();
        $path = $this->installer()->install();
        file_put_contents($path, 'corrupted');
        $dir = dirname($path);
        $versionDir = dirname($dir);
        $binary = $this->binary;
        $hash = $this->binaryHash;
        $other = $this->installer();
        $sweeps = 0;
        $sweep = static function () use ($other, $versionDir, &$sweeps): void {
            $sweeps++;
            (function () use ($versionDir): void {
                $this->sweep($versionDir);
            })->call($other);
        };
        // A good install lands just before ours is moved aside, as in the test
        // above, and it's an old directory. Another process sweeps just before
        // it's put back.
        $installer = $this->withRename($this->installer(), static function (string $from, string $to) use ($dir, $binary, $hash, $sweep): bool {
            if ($from === $dir && str_contains($to, '.stale-')) {
                file_put_contents("{$dir}/dynoxide", $binary);
                file_put_contents("{$dir}/dynoxide.sha256", $hash . "\n");
                file_put_contents("{$dir}/marker", 'theirs');
                touch($dir, time() - 7200);
            }
            if (str_contains($from, '.stale-')) {
                $sweep();
            }

            return rename($from, $to);
        });

        $installer->install();

        self::assertSame(1, $sweeps, 'The other process should have swept while the install was aside');
        self::assertSame('theirs', file_get_contents("{$dir}/marker"), 'The other process\'s install should be kept');
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
    }
}
