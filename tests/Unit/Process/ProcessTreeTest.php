<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Process;

use Dynoxide\Process\ProcessTree;
use Dynoxide\Tests\Support\FakeBinary;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unix')]
final class ProcessTreeTest extends TestCase
{
    use TempDir;

    /**
     * A tree bigger than any fixed limit on the walk is killed whole, down to
     * the grandchildren, and inside the walk's time limit: one cut short
     * kills what it has found but not the children of what it hasn't walked.
     * The tree is started up front and left running, so its size is certain.
     */
    public function testKillReachesEveryChildOfALargeTree(): void
    {
        $dir = $this->tempDir();
        $log = $dir . '/children';
        // Each child starts a grandchild that logs itself: 301 processes.
        $count = 150;
        file_put_contents($dir . '/child.sh', <<<'SH'
            sh -c 'echo $$ >> "$1"; exec sleep 30' sh "$1" &
            wait
            SH);
        $script = $dir . '/many.sh';
        file_put_contents($script, <<<SH
            i=0
            while [ \$i -lt {$count} ]; do
                sh {$dir}/child.sh {$log} &
                i=\$((i + 1))
            done
            wait
            SH);
        $wrapper = proc_open(['/bin/sh', $script], [], $pipes);
        self::assertIsResource($wrapper);
        $pid = (int) proc_get_status($wrapper)['pid'];
        try {
            $deadline = microtime(true) + 20;
            while (count(@file($log, FILE_IGNORE_NEW_LINES) ?: []) < $count) {
                self::assertLessThan($deadline, microtime(true), 'the wrapper never started all its children');
                usleep(50_000);
                clearstatcache();
            }

            // The tree has stopped growing, so this times one read of the
            // process table. The old per-process walk took about 7 s here on macOS.
            $started = hrtime(true);
            $tree = ProcessTree::of($pid);
            self::assertLessThan(4.0, (hrtime(true) - $started) / 1e9, 'reading the tree took a call per process');
            self::assertGreaterThan(2 * $count, count($tree), 'the walk stopped short of the whole tree');
            $started = hrtime(true);
            ProcessTree::kill($pid);
            $seconds = (hrtime(true) - $started) / 1e9;
            // The old walk took about 11 s for this tree on macOS and this one
            // well under a second. 8 s leaves a slow runner room while still
            // sitting below the walk's ten-second limit.
            self::assertLessThan(8.0, $seconds, 'the walk ran close to its ten-second limit');
            usleep(500_000);

            $children = array_map('intval', file($log, FILE_IGNORE_NEW_LINES) ?: []);
            $alive = array_values(array_filter($children, static fn (int $c): bool => FakeBinary::alive($c)));
            self::assertSame([], $alive, count($alive) . ' of ' . count($children) . ' children survived');
        } finally {
            foreach (array_map('intval', @file($log, FILE_IGNORE_NEW_LINES) ?: []) as $c) {
                if (FakeBinary::alive($c)) {
                    FakeBinary::kill($c);
                }
            }
            proc_terminate($wrapper, 9);
            proc_close($wrapper);
        }
    }

    /**
     * A wrapper can start a child between the tree being read and the kill.
     * Stopping each process before reading its children closes that gap, so
     * even a wrapper that keeps starting children leaves none behind.
     */
    public function testKillLeavesNothingAWrapperKeepsStarting(): void
    {
        $dir = $this->tempDir();
        $log = var_export($dir . '/children', true);
        // Each child logs itself, so one started just before the stop is seen.
        $child = var_export([PHP_BINARY, '-r', 'file_put_contents(' . $log . ', getmypid() . PHP_EOL, FILE_APPEND | LOCK_EX); sleep(20);'], true);
        $script = $dir . '/spawner.php';
        file_put_contents($script, <<<PHP
            <?php
            \$kept = [];
            for (\$i = 0; \$i < 400; \$i++) {
                \$c = proc_open({$child}, [], \$p);
                \$kept[] = \$c;
                usleep(2_000);
            }
            sleep(20);
            PHP);
        $wrapper = proc_open([PHP_BINARY, $script], [], $pipes);
        self::assertIsResource($wrapper);
        $pid = (int) proc_get_status($wrapper)['pid'];
        $children = [];
        try {
            $deadline = microtime(true) + 10;
            while (count(@file($dir . '/children', FILE_IGNORE_NEW_LINES) ?: []) < 20) {
                self::assertLessThan($deadline, microtime(true), 'the wrapper never started children');
                usleep(20_000);
                clearstatcache();
            }

            ProcessTree::kill($pid);
            usleep(500_000);

            $children = array_map('intval', file($dir . '/children', FILE_IGNORE_NEW_LINES) ?: []);
            $alive = array_values(array_filter($children, static fn (int $c): bool => FakeBinary::alive($c)));
            self::assertSame([], $alive, count($alive) . ' of ' . count($children) . ' children survived');
        } finally {
            foreach (array_map('intval', @file($dir . '/children', FILE_IGNORE_NEW_LINES) ?: []) as $c) {
                if (FakeBinary::alive($c)) {
                    FakeBinary::kill($c);
                }
            }
            proc_terminate($wrapper, 9);
            proc_close($wrapper);
        }
    }

    /**
     * A stop signal sent while the wrapper is inside fork() only takes effect
     * once the fork has finished, and the new child can appear just after
     * the children were read. Forking without a pause makes that likely
     * within a few rounds. Each child waits before it logs itself, so the
     * forking isn't slowed down, and only a child that was missed lives long
     * enough to log. Any process still running that names the test's
     * directory counts as well, so a child that was stopped but never killed
     * is a survivor too, without the forking being slowed to log it.
     */
    public function testKillCatchesAChildForkedAsTheWrapperIsStopped(): void
    {
        $dir = $this->tempDir();
        $log = var_export($dir . '/children', true);
        $script = $dir . '/forker.php';
        $fork = function_exists('pcntl_fork')
            ? '$p = pcntl_fork(); if ($p === 0) { usleep(300_000); file_put_contents(' . $log . ', getmypid() . PHP_EOL, FILE_APPEND | LOCK_EX); sleep(20); exit(0); }'
            : '$kept[] = proc_open(' . var_export([PHP_BINARY, '-r', 'usleep(300_000); file_put_contents(' . $log . ', getmypid() . PHP_EOL, FILE_APPEND | LOCK_EX); sleep(20);'], true) . ', [], $pipes);';
        file_put_contents($script, "<?php\n\$kept = [];\nfor (\$i = 0; \$i < 400; \$i++) { {$fork} }\nsleep(20);\n");

        $rounds = 15;
        $survivors = [];
        try {
            for ($round = 0; $round < $rounds && $survivors === []; $round++) {
                @unlink($dir . '/children');
                $wrapper = proc_open([PHP_BINARY, $script], [], $pipes);
                self::assertIsResource($wrapper);
                usleep(random_int(30_000, 90_000));

                $wrapperPid = (int) proc_get_status($wrapper)['pid'];
                ProcessTree::kill($wrapperPid);
                usleep(500_000);

                $deadline = microtime(true) + 1;
                do {
                    usleep(20_000);
                    clearstatcache();
                    $children = array_diff(array_unique(array_merge(
                        array_map('intval', @file($dir . '/children', FILE_IGNORE_NEW_LINES) ?: []),
                        self::runningWithArgument($dir),
                    )), [$wrapperPid]);
                    $survivors = array_values(array_filter($children, static fn (int $c): bool => FakeBinary::alive($c)));
                } while ($survivors !== [] && microtime(true) < $deadline);
                // A wrapper left stopped would hold proc_close() for ever.
                proc_terminate($wrapper, 9);
                proc_close($wrapper);
            }
            self::assertSame([], $survivors, 'a child survived in round ' . $round . ': ' . implode(', ', array_map(static fn (int $c): string => $c . ' (' . FakeBinary::describe($c) . ')', $survivors)));
        } finally {
            foreach ($survivors as $c) {
                FakeBinary::kill($c);
            }
        }
    }

    /**
     * Every live process whose command line contains $text.
     *
     * @return list<int>
     */
    private static function runningWithArgument(string $text): array
    {
        $pids = [];
        if (is_dir('/proc/self')) {
            foreach (scandir('/proc') ?: [] as $entry) {
                if (ctype_digit($entry) && str_contains((string) @file_get_contents("/proc/{$entry}/cmdline"), $text)) {
                    $pids[] = (int) $entry;
                }
            }
        } else {
            exec('ps -A -ww -o pid= -o command= 2>/dev/null', $lines);
            foreach ($lines as $line) {
                if (str_contains($line, $text)) {
                    $pids[] = (int) $line;
                }
            }
        }

        return array_values(array_filter($pids, static fn (int $pid): bool => $pid !== getmypid() && FakeBinary::alive($pid)));
    }
}
