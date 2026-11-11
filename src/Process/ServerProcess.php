<?php

declare(strict_types=1);

namespace Dynoxide\Process;

/**
 * A running server process: its handle, the stdin pipe that keeps it alive,
 * and the directory its output goes to. Knows how to stop it.
 *
 * Only the PHP process that started it can stop it. A process forked after
 * the start shares the handle, but there stop() does nothing: PHP can't see
 * the server's status from a child, and a stop there would delete the
 * directory the server is still writing to.
 *
 * @internal
 */
final class ServerProcess
{
    /** @var array{exitcode: int, signaled: bool, termsig: int}|null the first status that said it had stopped */
    private ?array $exited = null;

    private bool $closed = false;

    private readonly int $owner;

    /** The process ID PHP was given, kept for once the handle can't report it. */
    public readonly int $pid;

    /** @var array<int, string> what the process had started when it was ready, by start time */
    private array $knownTree = [];

    /**
     * @param resource $process
     * @param resource|null $stdin
     * @param bool $launcherFlags whether it was started with --exit-with-parent, so closing stdin stops it
     * @param float $graceSeconds how long each step of stopping waits before the next
     */
    public function __construct(
        private $process,
        private $stdin,
        public readonly string $outputDir,
        public readonly bool $launcherFlags,
        private readonly float $graceSeconds = 5.0,
        private readonly bool $windows = PHP_OS_FAMILY === 'Windows',
    ) {
        $this->owner = getmypid();
        // Before PHP 8.3 a stopped process's status is reported once, so a
        // binary that has already died keeps it here, not lost.
        $status = proc_get_status($process);
        $this->pid = (int) $status['pid'];
        if (!$status['running']) {
            $this->exited = [
                'exitcode' => $status['exitcode'],
                'signaled' => $status['signaled'],
                'termsig' => $status['termsig'],
            ];
        }
    }

    /**
     * Records what the process has started, once it's ready. A wrapper that
     * then exits by itself leaves its child re-parented, and stop() couldn't
     * find it from the wrapper any more.
     *
     * @return array<int, string> what it has started, by start time
     */
    public function rememberTree(): array
    {
        if (!$this->windows && $this->exited() === null) {
            $this->knownTree = ProcessTree::of($this->pid);
        }

        return $this->knownTree;
    }

    /**
     * The last resort. PHP stops running shutdown functions once one calls
     * exit(), as PHPUnit's does after a test calls exit(), but it still
     * destroys objects, so a server Registry::stopAll() never reached is
     * stopped here. After a fatal error (out of memory, a time limit) PHP runs
     * no destructors either: a 1.7.0 or later server still stops when its
     * stdin closes, but an older one keeps running and the output directory
     * stays behind, until the next start clears both up (see Leftovers).
     */
    public function __destruct()
    {
        $this->stop();
    }

    /** Whether this PHP process started it, rather than being forked from the one that did. */
    public function ownedHere(): bool
    {
        return getmypid() === $this->owner;
    }

    /**
     * The exit status once it has stopped, or null while it runs. PHP
     * reports some statuses only once, so the first one is kept. In a
     * forked process, which can't wait on the server, it's always null.
     *
     * @return array{exitcode: int, signaled: bool, termsig: int}|null
     */
    public function exited(): ?array
    {
        if ($this->exited === null && !$this->closed && $this->ownedHere()) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $this->exited = [
                    'exitcode' => $status['exitcode'],
                    'signaled' => $status['signaled'],
                    'termsig' => $status['termsig'],
                ];
            }
        }

        return $this->exited;
    }

    /**
     * Stops it by the launcher contract's procedure: close stdin, wait; on
     * Linux and macOS send SIGTERM, wait; then kill. A server started without
     * --exit-with-parent skips the first step, and on Windows, where there's
     * no SIGTERM, goes straight to the kill. Safe to call more than once.
     *
     * The signals go to everything the process started too, as recorded when
     * it was ready and again before stdin is closed, and anything of that
     * left running once the process has gone is stopped as well: a binary
     * reached through a wrapper is the wrapper's child. Each is checked by
     * its start time first. On Windows the kill takes the whole tree, but a
     * child left behind by a wrapper that exited by itself isn't found.
     * Once it has stopped it leaves the Registry and its output is deleted.
     *
     * @return array{exitcode: int, signaled: bool, termsig: int}|null how it exited, or null if it
     *         couldn't be stopped or this is a forked process
     */
    public function stop(): ?array
    {
        if (!$this->ownedHere()) {
            return null;
        }
        if ($this->closed) {
            return $this->exited;
        }
        // Read before anything is signalled: the server may be a wrapper's
        // child, and once the wrapper dies it can't be found from it.
        $pid = $this->pid;
        $tree = $this->windows ? [] : ($this->exited() === null ? ProcessTree::of($pid) : []) + $this->knownTree;
        // If it has already gone, nothing waits for stdin to take effect.
        $graceSkipped = $this->exited() !== null;
        $this->closeStdin();
        if ($this->launcherFlags) {
            $this->waitForExit($this->graceSeconds);
        }
        if ($this->exited() === null && !$this->windows) {
            ProcessTree::signal($tree, 15);
            @proc_terminate($this->process, 15);
            $this->waitForExit($this->graceSeconds);
        }
        if ($this->exited() === null) {
            if ($this->windows) {
                ProcessTree::killOnWindows($pid);
            } else {
                ProcessTree::kill($pid);
                ProcessTree::signal($tree, 9);
            }
            @proc_terminate($this->process, 9);
            $this->waitForExit($this->graceSeconds);
        }
        unset($tree[$pid]);
        $this->stopLeftovers($tree, $this->launcherFlags && $graceSkipped);
        // proc_close waits for the process, so only once it's known to be gone.
        if ($this->exited() !== null) {
            proc_close($this->process);
            $this->closed = true;
            self::removeDir($this->outputDir);
            Registry::remove($this);
        }

        return $this->exited;
    }

    /** The last lines it wrote to stderr, for error messages. */
    public function stderrTail(int $lines = 20): string
    {
        $file = $this->outputDir . DIRECTORY_SEPARATOR . 'stderr.log';
        $size = @filesize($file);
        if ($size === false || $size === 0) {
            return '';
        }
        $text = (string) @file_get_contents($file, false, null, max(0, $size - 8192));
        $tail = array_slice(preg_split('/\r?\n/', rtrim($text)) ?: [], -$lines);

        return (string) preg_replace('/[^\x09\x0a\x20-\x7e]+/', ' ', implode("\n", $tail));
    }

    /**
     * What a wrapper started can outlive the wrapper, for example when SIGTERM
     * stopped the wrapper but not the server it forked.
     *
     * @param array<int, string> $tree
     */
    private function stopLeftovers(array $tree, bool $waitFirst): void
    {
        if (ProcessTree::running($tree) === []) {
            return;
        }
        // A server started with --exit-with-parent stops by itself once
        // stdin closes, which it shares with the wrapper. When the wrapper
        // had already gone, nothing has waited for that yet, so wait first.
        if ($waitFirst) {
            $deadline = microtime(true) + $this->graceSeconds;
            while (ProcessTree::running($tree) !== [] && microtime(true) < $deadline) {
                usleep(10_000);
            }
        }
        ProcessTree::signal($tree, 15);
        $deadline = microtime(true) + $this->graceSeconds;
        while (ProcessTree::running($tree) !== [] && microtime(true) < $deadline) {
            usleep(10_000);
        }
        ProcessTree::killAll($tree);
    }

    private function closeStdin(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        $this->stdin = null;
    }

    private function waitForExit(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while ($this->exited() === null && microtime(true) < $deadline) {
            usleep(10_000);
        }
    }

    public static function removeDir(string $dir): void
    {
        foreach (@scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($dir);
    }
}
