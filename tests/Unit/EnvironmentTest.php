<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit;

use Dynoxide\Cache\CacheDirectory;
use Dynoxide\Cache\Installer;
use Dynoxide\Exception\DownloadException;
use Dynoxide\Platform;
use Dynoxide\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testAddsUpperCaseNamesOnWindowsAndKeepsTheOriginals(): void
    {
        self::assertSame(
            ['Path' => 'C:\\bin', 'https_proxy' => 'http://p', 'HOME' => 'h', 'PATH' => 'C:\\bin', 'HTTPS_PROXY' => 'http://p'],
            Environment::normalise(['Path' => 'C:\\bin', 'https_proxy' => 'http://p', 'HOME' => 'h'], 'Windows'),
        );
    }

    public function testAnUpperCaseNameAlreadyThereWins(): void
    {
        self::assertSame('upper', Environment::normalise(['Path' => 'mixed', 'PATH' => 'upper'], 'Windows')['PATH']);
        self::assertSame('upper', Environment::normalise(['PATH' => 'upper', 'Path' => 'mixed'], 'Windows')['PATH']);
    }

    public function testChangesNothingElsewhere(): void
    {
        self::assertSame(['Path' => '/bin'], Environment::normalise(['Path' => '/bin'], 'Linux'));
    }

    public function testTheInstallerReadsTheMirrorInAnyCaseOnWindows(): void
    {
        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('ftp://mirror.example');

        Installer::forEmbeddedRelease(
            ['Dynoxide_Download_Base_Url' => 'ftp://mirror.example', 'HOME' => '/home/me', 'LOCALAPPDATA' => 'C:\\Local'],
            Platform::fromTarget('x86_64-pc-windows-msvc'),
        );
    }

    /** So one mixed-case setting can't apply to the binary but not the cache. */
    public function testTheCacheDirectoryIsReadInAnyCaseOnWindows(): void
    {
        $cache = CacheDirectory::fromEnvironment(['Dynoxide_Cache_Dir' => 'D:\\cache', 'LOCALAPPDATA' => 'C:\\Users\\me\\AppData\\Local'], 'Windows');

        self::assertSame('D:\\cache', $cache->root);
    }
}
