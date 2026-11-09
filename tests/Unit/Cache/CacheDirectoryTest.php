<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Cache;

use Dynoxide\Cache\CacheDirectory;
use Dynoxide\Exception\CacheException;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CacheDirectoryTest extends TestCase
{
    use TempDir;

    public function testDynoxideCacheDirWinsOverEverything(): void
    {
        $env = ['DYNOXIDE_CACHE_DIR' => '/x/cache', 'XDG_CACHE_HOME' => '/x/xdg', 'HOME' => '/x/home', 'LOCALAPPDATA' => 'C:\\L'];

        self::assertSame('/x/cache', CacheDirectory::fromEnvironment($env, 'Linux')->root);
        self::assertSame('/x/cache', CacheDirectory::fromEnvironment($env, 'Windows')->root);
    }

    public function testXdgCacheHomeIsNext(): void
    {
        $env = ['XDG_CACHE_HOME' => '/x/xdg/', 'HOME' => '/x/home'];

        self::assertSame('/x/xdg/dynoxide', CacheDirectory::fromEnvironment($env, 'Linux')->root);
    }

    public function testHomeOnLinuxAndMacos(): void
    {
        $env = ['HOME' => '/x/home', 'LOCALAPPDATA' => 'C:\\L'];

        self::assertSame('/x/home/.cache/dynoxide', CacheDirectory::fromEnvironment($env, 'Linux')->root);
        self::assertSame('/x/home/.cache/dynoxide', CacheDirectory::fromEnvironment($env, 'Darwin')->root);
    }

    public function testLocalAppDataOnWindows(): void
    {
        $env = ['HOME' => 'C:\\Users\\me', 'LOCALAPPDATA' => 'C:\\Users\\me\\AppData\\Local'];

        self::assertSame('C:\\Users\\me\\AppData\\Local\\dynoxide', CacheDirectory::fromEnvironment($env, 'Windows')->root);
    }

    public function testEmptyVariablesAreIgnored(): void
    {
        $env = ['DYNOXIDE_CACHE_DIR' => '', 'XDG_CACHE_HOME' => '', 'HOME' => '/x/home'];

        self::assertSame('/x/home/.cache/dynoxide', CacheDirectory::fromEnvironment($env, 'Linux')->root);
    }

    public function testNoHomeIsAnError(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('DYNOXIDE_CACHE_DIR');
        CacheDirectory::fromEnvironment([], 'Linux');
    }

    public function testCreatesEachLevelPrivately(): void
    {
        $root = $this->tempDir() . '/cache/dynoxide';
        $cache = new CacheDirectory($root);

        $path = $cache->prepare('1.7.0', 'aarch64-apple-darwin');

        self::assertSame($root . DIRECTORY_SEPARATOR . '1.7.0' . DIRECTORY_SEPARATOR . 'aarch64-apple-darwin', $path);
        self::assertDirectoryExists($path);
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach ([$root, "{$root}/1.7.0", $path] as $dir) {
                self::assertSame(0700, fileperms($dir) & 0777, $dir);
            }
        }
    }

    public function testAnRcAndItsReleaseGetSeparateDirectories(): void
    {
        $cache = new CacheDirectory($this->tempDir() . '/c');

        self::assertNotSame($cache->prepare('1.7.0-rc.1'), $cache->prepare('1.7.0'));
    }

    #[Group('unix')]
    public function testAnUnwritableRootNamesThePathAndTheVariable(): void
    {
        $this->skipOnWindows();
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $parent = $this->tempDir() . '/locked';
        mkdir($parent, 0500);
        $root = "{$parent}/dynoxide";
        $before = $this->tempEntries();

        try {
            (new CacheDirectory($root))->prepare('1.7.0');
            self::fail('Expected a failure');
        } catch (CacheException $e) {
            self::assertStringContainsString($root, $e->getMessage());
            self::assertStringContainsString('DYNOXIDE_CACHE_DIR', $e->getMessage());
        }
        self::assertSame($before, $this->tempEntries(), 'Nothing should be written to the system temp directory');
    }

    #[Group('unix')]
    public function testRefusesAGroupWritableDirectory(): void
    {
        $this->skipOnWindows();
        $root = $this->tempDir() . '/c';
        mkdir($root, 0700);
        chmod($root, 0770);

        try {
            (new CacheDirectory($root))->prepare('1.7.0');
            self::fail('Expected a refusal');
        } catch (CacheException $e) {
            self::assertStringContainsString($root, $e->getMessage());
            self::assertStringContainsString('chmod 700', $e->getMessage());
        }
    }

    #[Group('unix')]
    public function testRefusesAWorldWritableDirectoryBelowTheRoot(): void
    {
        $this->skipOnWindows();
        $root = $this->tempDir() . '/c';
        mkdir("{$root}/1.7.0", 0700, true);
        chmod("{$root}/1.7.0", 0702);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("{$root}/1.7.0");
        (new CacheDirectory($root))->prepare('1.7.0');
    }

    #[Group('unix')]
    public function testRefusesADirectoryOwnedBySomeoneElse(): void
    {
        $this->skipOnWindows();
        $root = $this->tempDir() . '/c';
        $someoneElse = (int) fileowner($this->tempDir()) + 1;

        try {
            (new CacheDirectory($root, true, $someoneElse))->prepare('1.7.0');
            self::fail('Expected a refusal');
        } catch (CacheException $e) {
            self::assertStringContainsString("not by you (user {$someoneElse})", $e->getMessage());
            self::assertStringContainsString('chown', $e->getMessage());
            self::assertStringContainsString('DYNOXIDE_CACHE_DIR', $e->getMessage());
        }
    }

    #[Group('unix')]
    public function testDoesNotCheckTheParents(): void
    {
        $this->skipOnWindows();
        $parent = $this->tempDir() . '/shared';
        mkdir($parent, 0700);
        chmod($parent, 0777);

        self::assertDirectoryExists((new CacheDirectory("{$parent}/dynoxide"))->prepare('1.7.0'));
    }

    #[Group('unix')]
    public function testFollowsASymlinkedRootButNothingBelowIt(): void
    {
        $this->skipOnWindows();
        $real = $this->tempDir() . '/real';
        mkdir($real, 0700);
        symlink($real, $this->tempDir() . '/link');
        $cache = new CacheDirectory($this->tempDir() . '/link');

        self::assertDirectoryExists($cache->prepare('1.7.0'));

        mkdir($this->tempDir() . '/elsewhere', 0700);
        symlink($this->tempDir() . '/elsewhere', "{$real}/1.8.0");
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('symlink');
        $cache->prepare('1.8.0');
    }

    public function testWindowsSkipsTheOwnershipChecks(): void
    {
        $root = $this->tempDir() . '/c';
        mkdir($root, 0777);
        @chmod($root, 0777);

        self::assertDirectoryExists((new CacheDirectory($root, false, 12345))->prepare('1.7.0'));
    }

    private function skipOnWindows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows relies on %LOCALAPPDATA% being per-user');
        }
    }

    /** @return list<string> */
    private function tempEntries(): array
    {
        $entries = array_values(array_filter(
            scandir(sys_get_temp_dir()) ?: [],
            static fn (string $e): bool => str_contains($e, 'dynoxide') && !str_starts_with($e, 'dynoxide-test-'),
        ));
        sort($entries);

        return $entries;
    }
}
