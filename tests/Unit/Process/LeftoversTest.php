<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Process;

use Dynoxide\Process\Leftovers;
use Dynoxide\Process\ProcessTree;
use Dynoxide\Process\ServerProcess;
use Dynoxide\Tests\Support\FakeBinary;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unix')]
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

    /**
     * A recorded server ID can have been freed and taken again by a new
     * child of the same server. The sweep walks the server, finds that child
     * under its own start time, and must stop it too rather than keep the
     * stale start time and skip it.
     */
    public function testASweepStopsAChildThatReusedARecordedId(): void
    {
        $root = $this->tempDir();
        $dir = $root . '/dynoxide-' . str_repeat('7e', 8);
        mkdir($dir, 0700);
        $log = $root . '/child';
        $server = proc_open(['/bin/sh', '-c', 'sleep 30 & echo $! > "$1"; wait', 'sh', $log], [], $pipes);
        self::assertIsResource($server);
        $serverPid = (int) proc_get_status($server)['pid'];
        $child = 0;
        try {
            $deadline = microtime(true) + 10;
            while (!is_file($log) || trim((string) file_get_contents($log)) === '') {
                self::assertLessThan($deadline, microtime(true), 'the server never started its child');
                usleep(20_000);
                clearstatcache();
            }
            $child = (int) trim((string) file_get_contents($log));
            $record = [
                'clock' => ProcessTree::clock(),
                'owner' => [1, 'not when it started'],
                'server' => [$serverPid => ProcessTree::startTime($serverPid), $child => 'an earlier process with this ID'],
            ];
            file_put_contents($dir . '/owner.json', json_encode($record));

            Leftovers::sweep($root, 1.0);
            usleep(200_000);

            self::assertNull(ProcessTree::startTime($child), 'the child that reused a recorded ID was left running');
        } finally {
            if ($child > 0 && ProcessTree::startTime($child) !== null) {
                FakeBinary::kill($child);
            }
            proc_terminate($server, 9);
            proc_close($server);
        }
    }

    /**
     * A directory with no owner.json yet (being set up, or left by a run that
     * died first) is skipped without a warning. A warning silenced with @
     * still reaches the error handler, and Pest 3 and 4 report it, so a
     * leftover like this would show "1 warning" on every later run.
     */
    public function testADirectoryWithoutARecordIsSkippedWithoutAWarning(): void
    {
        $root = $this->tempDir();
        $dir = $root . '/dynoxide-' . str_repeat('ef', 8);
        mkdir($dir, 0700);
        touch("{$dir}/stdout.log");
        touch("{$dir}/stderr.log");

        self::assertSame([], self::sweepCollectingWarnings($root));
        self::assertDirectoryExists($dir);
    }

    /**
     * On Linux, checking whether a gone owner and server are running reads
     * /proc files that no longer exist. That mustn't warn either.
     */
    public function testARecordWhoseOwnerAndServerHaveGoneIsClearedWithoutAWarning(): void
    {
        $root = $this->tempDir();
        $dir = $root . '/dynoxide-' . str_repeat('9a', 8);
        mkdir($dir, 0700);
        file_put_contents("{$dir}/owner.json", json_encode([
            'clock' => ProcessTree::clock(),
            'owner' => [999_999, 'long gone'],
            'server' => [999_998 => 'long gone'],
        ]));

        self::assertSame([], self::sweepCollectingWarnings($root));
        self::assertDirectoryDoesNotExist($dir);
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

    /**
     * Sweeps $root and returns every warning raised, silenced with @ or not:
     * Pest reports both.
     *
     * @return list<string>
     */
    private static function sweepCollectingWarnings(string $root): array
    {
        return self::collectingWarnings(static fn () => Leftovers::sweep($root, 0.1));
    }

    /**
     * Two sweeps can find the same leftover, and the slower one then removes
     * a directory that has already gone.
     */
    public function testRemovingADirectoryThatHasGoneRaisesNoWarning(): void
    {
        $dir = $this->tempDir() . '/dynoxide-' . str_repeat('5c', 8);
        mkdir($dir, 0700);
        touch("{$dir}/stdout.log");
        ServerProcess::removeDir($dir);

        self::assertSame([], self::collectingWarnings(static fn () => ServerProcess::removeDir($dir)));
        self::assertDirectoryDoesNotExist($dir);
    }

    /**
     * Without the posix extension the user is found from a probe file, and
     * a root that has gone makes tempnam() warn. An error handler must not
     * see that.
     */
    public function testFindingTheUserWithoutPosixRaisesNoWarning(): void
    {
        $gone = $this->tempDir() . '/gone';
        $user = null;

        self::assertSame([], self::collectingWarnings(static function () use ($gone, &$user): void {
            $user = Leftovers::user($gone, false);
        }));
        self::assertIsInt($user, 'the probe file was not made in the system temporary directory');
    }

    /** @return list<string> */
    private static function collectingWarnings(callable $run): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }
}
