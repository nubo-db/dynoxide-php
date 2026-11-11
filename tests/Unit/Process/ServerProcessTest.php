<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Unit\Process;

use Dynoxide\Process\ServerProcess;
use Dynoxide\Tests\Support\FakeBinary;
use Dynoxide\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ServerProcessTest extends TestCase
{
    use TempDir;

    /** @var array<int, string> what the test started, for clean-up */
    private array $started = [];

    protected function tearDown(): void
    {
        FakeBinary::killIdentified($this->started);
        $this->started = [];
    }

    /**
     * A server reached through a wrapper that forked it, and that doesn't
     * stop when stdin closes (a 1.6.x server): signalling only the wrapper
     * would leave the server running, holding its port and database.
     */
    public function testStoppingAlsoStopsWhatAWrapperStarted(): void
    {
        [$server, $child] = $this->startWrapped();

        $server->stop();

        self::assertNotNull($server->exited());
        self::assertGone($child);
    }

    /**
     * A wrapper that exits by itself leaves its child re-parented, where it
     * can't be found from the wrapper any more. What the process had started
     * when it was ready is remembered, so the child is still stopped.
     */
    #[Group('unix')]
    public function testStoppingAlsoStopsWhatAWrapperLeftBehind(): void
    {
        [$server, $child, $pidFile] = $this->startWrapped();
        $server->rememberTree();
        touch($pidFile . '.exit');
        $deadline = microtime(true) + 10;
        while ($server->exited() === null) {
            self::assertLessThan($deadline, microtime(true), "the wrapper didn't exit");
            usleep(20_000);
        }
        self::assertTrue(FakeBinary::alive($child), 'the child outlived the wrapper');

        $server->stop();

        self::assertGone($child);
    }

    /**
     * Before PHP 8.3, proc_get_status() reports a stopped process's status
     * once. A binary that dies at once can do so before the ServerProcess is
     * made, and its exit code still has to reach the error message.
     */
    public function testAProcessThatHasAlreadyExitedKeepsItsStatus(): void
    {
        $process = proc_open([PHP_BINARY, '-r', 'exit(127);'], [0 => ['pipe', 'r']], $pipes);
        self::assertIsResource($process);
        usleep(500_000);
        $outputDir = $this->tempDir() . DIRECTORY_SEPARATOR . 'output';
        mkdir($outputDir);

        $server = new ServerProcess($process, $pipes[0], $outputDir, launcherFlags: false, graceSeconds: 1.0);

        self::assertSame(127, $server->exited()['exitcode'] ?? null);
        $server->stop();
    }

    /** @return array{ServerProcess, int, string} */
    private function startWrapped(): array
    {
        $pidFile = $this->tempDir() . DIRECTORY_SEPARATOR . 'grandchild.pid';
        $fake = FakeBinary::forking($this->tempDir(), $pidFile);
        $process = proc_open($fake->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->started += FakeBinary::identify((int) proc_get_status($process)['pid']);

        $deadline = microtime(true) + 10;
        while ((int) @file_get_contents($pidFile) === 0) {
            self::assertLessThan($deadline, microtime(true), 'the wrapper never started its child');
            usleep(20_000);
        }
        $child = (int) file_get_contents($pidFile);
        $this->started += FakeBinary::identify($child);

        $outputDir = $this->tempDir() . DIRECTORY_SEPARATOR . 'output';
        mkdir($outputDir);

        return [new ServerProcess($process, $pipes[0], $outputDir, launcherFlags: false, graceSeconds: 1.0), $child, $pidFile];
    }

    private static function assertGone(int $pid): void
    {
        $deadline = microtime(true) + 3;
        while (FakeBinary::alive($pid) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertFalse(FakeBinary::alive($pid), "the wrapper's child, {$pid}, is still running");
    }
}
