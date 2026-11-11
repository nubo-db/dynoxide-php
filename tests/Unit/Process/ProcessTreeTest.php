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
}
