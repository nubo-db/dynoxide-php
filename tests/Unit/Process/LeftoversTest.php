<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Process;

use Dynoxide\Process\Leftovers;
use Dynoxide\Process\ProcessTree;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[Group('unix')]
#[RequiresPhpExtension('posix')]
final class LeftoversTest extends TestCase
{
    use TempDir;

    /**
     * Another PHP process can run under a different time zone and locale.
     * Its live directory must still look live from here.
     */
    public function testALiveOwnerWithAnotherTimeZoneAndLocaleIsLeftAlone(): void
    {
        $root = $this->tempDir();
        $dir = $root . '/dynoxide-' . str_repeat('ab', 8);
        mkdir($dir, 0700);
        $script = sprintf(
            'require %s; %s::record(%s); echo "recorded\n"; sleep(10);',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            Leftovers::class,
            var_export($dir, true),
        );
        $env = ['TZ' => 'Asia/Tokyo', 'LC_ALL' => 'fr_FR.UTF-8', 'LANG' => 'fr_FR.UTF-8'] + getenv();
        $owner = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($owner);
        try {
            self::assertSame("recorded\n", fgets($pipes[1]));

            Leftovers::sweep($root, 0.1);

            self::assertDirectoryExists($dir);
        } finally {
            proc_terminate($owner, 9);
            proc_close($owner);
        }
    }

    /** A record written under another clock can't be judged, so it's left. */
    public function testARecordFromAnotherClockIsLeftAlone(): void
    {
        $root = $this->tempDir();
        $dir = $root . '/dynoxide-' . str_repeat('cd', 8);
        mkdir($dir, 0700);
        file_put_contents("{$dir}/owner.json", json_encode([
            'clock' => 'elsewhere',
            'owner' => [999_999, 'long gone'],
            'server' => [],
        ]));

        Leftovers::sweep($root, 0.1);

        self::assertDirectoryExists($dir);
    }

    /** A server given up on earlier is cleared once it has gone by itself. */
    public function testAnAbandonedRecordIsClearedOnceItsServerHasGone(): void
    {
        $root = $this->tempDir();
        $dir = $root . '/dynoxide-' . str_repeat('01', 8);
        mkdir($dir, 0700);
        file_put_contents("{$dir}/owner.json", json_encode([
            'clock' => ProcessTree::clock(),
            'owner' => [999_999, 'long gone'],
            'server' => [999_998 => 'long gone'],
            'abandoned' => true,
        ]));

        Leftovers::sweep($root, 0.1);

        self::assertDirectoryDoesNotExist($dir);
    }

    /** The same record under this clock, with its owner gone, is cleared. */
    public function testARecordWhoseOwnerHasGoneIsCleared(): void
    {
        $root = $this->tempDir();
        $dir = $root . '/dynoxide-' . str_repeat('ef', 8);
        mkdir($dir, 0700);
        file_put_contents("{$dir}/owner.json", json_encode([
            'clock' => ProcessTree::clock(),
            'owner' => [999_999, 'long gone'],
            'server' => [],
        ]));

        Leftovers::sweep($root, 0.1);

        self::assertDirectoryDoesNotExist($dir);
    }
}
