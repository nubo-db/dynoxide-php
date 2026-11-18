<?php

declare(strict_types=1);

namespace Dynoxide\Process;

/**
 * Signals a process and everything it started. A binary reached through a
 * wrapper (a version manager's shim, a script that forks the real binary)
 * runs as the wrapper's child, so signalling only the process PHP started
 * would leave the real one running.
 *
 * Each process is recorded with its start time, and is signalled only while
 * that still matches, so a process ID reused by something else is left
 * alone. A zombie counts as gone: in a container whose first process doesn't
 * reap orphans, a killed child stays one.
 *
 * Linux reads /proc. macOS, and Linux without /proc, use pgrep and ps; with
 * neither, or with exec() disabled, nothing is found, and only the process
 * PHP started is signalled, through its handle.
 *
 * @internal
 */
final class ProcessTree
{
    /** How long kill() keeps walking a tree before it kills what it has found. */
    private const KILL_WALK_SECONDS = 10.0;

    /** How long freeze() waits for a process to stop before going on. */
    private const STOP_WAIT_SECONDS = 1.0;

    /**
     * The process and everything below it, parents first, each with its
     * start time. Read it before signalling anything: once a parent dies its
     * children are re-parented and can't be found from it. There's no limit
     * on its size: each process is read once, so the walk always ends. Linux
     * and macOS only.
     *
     * @return array<int, string> process ID to start time
     */
    public static function of(int $pid): array
    {
        $start = self::startTime($pid);
        if ($start === null) {
            return [];
        }
        $tree = [$pid => $start];
        $children = self::childrenByParent();
        $queue = [$pid];
        while ($queue !== []) {
            foreach ($children === null ? self::pgrepChildren(array_shift($queue)) : ($children[array_shift($queue)] ?? []) as $child) {
                if (!isset($tree[$child]) && ($childStart = self::startTime($child)) !== null) {
                    $tree[$child] = $childStart;
                    $queue[] = $child;
                }
            }
        }

        return $tree;
    }

    /**
     * Sends $signal to each process in the tree that's still the one that
     * was recorded. Linux and macOS only.
     *
     * @param array<int, string> $tree
     */
    public static function signal(array $tree, int $signal): void
    {
        $pids = array_keys(self::running($tree));
        if ($pids === []) {
            return;
        }
        if (function_exists('posix_kill')) {
            foreach ($pids as $pid) {
                @posix_kill($pid, $signal);
            }

            return;
        }
        if (self::canExec()) {
            @exec('kill -' . $signal . ' ' . implode(' ', $pids) . ' 2>/dev/null');
        }
    }

    /**
     * The processes in the tree that are still running and still the ones
     * that were recorded.
     *
     * @param array<int, string> $tree
     * @return array<int, string>
     */
    public static function running(array $tree): array
    {
        return array_filter($tree, static fn (string $start, int $pid): bool => self::startTime($pid) === $start, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Kills the process and everything below it. Each is stopped (SIGSTOP),
     * and seen to have stopped, before its children are read, so none can
     * start another child between the read and the kill. Every process in the tree is reached, however
     * many there are: a cap on the count would leave the rest running. The
     * walk does stop after KILL_WALK_SECONDS, so a tree that forks faster
     * than it can be walked can't hold stop() for ever; whatever has been
     * found by then is still killed. Linux and macOS only.
     */
    public static function kill(int $pid): void
    {
        $start = self::startTime($pid);
        if ($start === null) {
            return;
        }
        $tree = [];
        $queue = [$pid => $start];
        // hrtime, unlike microtime, doesn't jump when the clock is changed.
        $deadline = hrtime(true) + (int) (self::KILL_WALK_SECONDS * 1e9);
        while ($queue !== [] && hrtime(true) < $deadline) {
            $current = array_key_first($queue);
            $tree[$current] = $queue[$current];
            unset($queue[$current]);
            self::freeze($current, $tree[$current]);
            $children = self::childrenByParent();
            foreach ($children === null ? self::pgrepChildren($current) : ($children[$current] ?? []) as $child) {
                if (!isset($tree[$child]) && ($childStart = self::startTime($child)) !== null) {
                    $queue[$child] = $childStart;
                }
            }
            // Its children are known, so it can go now: nothing is left
            // stopped if this is cut short.
            self::signal([$current => $tree[$current]], 9);
        }
        self::signal($tree + $queue, 9);
    }

    /**
     * Kills each process in the tree that's still the one recorded, and
     * everything it has started since.
     *
     * @param array<int, string> $tree
     */
    public static function killAll(array $tree): void
    {
        foreach (array_keys(self::running($tree)) as $pid) {
            self::kill($pid);
        }
        self::signal($tree, 9);
    }

    /** Kills the process and everything it started, on Windows. */
    public static function killOnWindows(int $pid): void
    {
        if (function_exists('exec')) {
            @exec('taskkill /T /F /PID ' . $pid . ' 2>NUL');
        }
    }

    /**
     * When the process started, or null if it isn't running, is a zombie or
     * can't be told. Linux and macOS only.
     */
    public static function startTime(int $pid): ?string
    {
        if (is_dir('/proc/self')) {
            $stat = self::quietly(static fn () => file_get_contents("/proc/{$pid}/stat"));
            if ($stat === false) {
                return null;
            }
            // The name in brackets can hold spaces; the fields after it don't.
            $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));

            return ($fields[0] ?? 'Z') === 'Z' ? null : ($fields[19] ?? null);
        }
        if (!self::canExec()) {
            return null;
        }
        $out = [];
        $status = 1;
        // lstart follows the locale and time zone; fix both, so another
        // process reads the same text.
        @exec('LC_ALL=C TZ=UTC0 ps -o stat= -o lstart= -p ' . $pid . ' 2>/dev/null', $out, $status);
        $line = trim($out[0] ?? '');
        if ($status !== 0 || $line === '' || $line[0] === 'Z') {
            return null;
        }

        return (string) preg_replace('/^\S+\s+/', '', $line);
    }

    /**
     * Every process's children, from /proc, or null without it.
     *
     * @return array<int, list<int>>|null
     */
    private static function childrenByParent(): ?array
    {
        if (!is_dir('/proc/self')) {
            return null;
        }
        $children = [];
        foreach (self::quietly(static fn () => scandir('/proc')) ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            // A process can exit between the listing and the read.
            $stat = self::quietly(static fn () => file_get_contents("/proc/{$entry}/stat"));
            if ($stat === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
            $children[(int) ($fields[1] ?? 0)][] = (int) $entry;
        }

        return $children;
    }

    /** @return list<int> */
    private static function pgrepChildren(int $pid): array
    {
        if (!self::canExec()) {
            return [];
        }
        $out = [];
        $status = 1;
        @exec('pgrep -P ' . $pid . ' 2>/dev/null', $out, $status);

        return $status === 0 ? array_map('intval', array_filter($out, static fn (string $l): bool => ctype_digit(trim($l)))) : [];
    }

    /**
     * What start times here are measured against: on Linux the boot and the
     * process ID namespace, elsewhere ps on this host. Start times read under
     * different clocks can't be compared, and the same process ID can mean
     * another process. Null when it can't be told.
     */
    public static function clock(): ?string
    {
        if (is_dir('/proc/self')) {
            $boot = self::quietly(static fn () => file_get_contents('/proc/sys/kernel/random/boot_id'));
            $namespace = self::quietly(static fn () => readlink('/proc/self/ns/pid'));

            return $boot === false || trim($boot) === '' || $namespace === false ? null : 'proc:' . trim($boot) . ':' . $namespace;
        }

        return self::canExec() ? 'ps:' . php_uname('n') : null;
    }

    /**
     * Sends SIGSTOP, by name where its number isn't known: it differs between
     * Linux, the BSDs and other systems. Then waits, for up to
     * STOP_WAIT_SECONDS, until the process has stopped or gone. The signal
     * only takes effect once a fork the process is in the middle of has
     * finished, and the new child can appear after a read of the children
     * that ran straight after the signal was sent.
     */
    private static function freeze(int $pid, string $start): void
    {
        if (self::running([$pid => $start]) === []) {
            return;
        }
        if (defined('SIGSTOP') && function_exists('posix_kill')) {
            @posix_kill($pid, SIGSTOP);
        } elseif (self::canExec()) {
            @exec('kill -s STOP ' . $pid . ' 2>/dev/null');
        } else {
            return;
        }
        $deadline = hrtime(true) + (int) (self::STOP_WAIT_SECONDS * 1e9);
        while (!in_array(self::state($pid), [null, 'T', 't', 'Z'], true) && hrtime(true) < $deadline) {
            usleep(1_000);
        }
    }

    /**
     * The first letter of the process's state (T when stopped), or null if
     * it isn't running or can't be told.
     */
    private static function state(int $pid): ?string
    {
        if (is_dir('/proc/self')) {
            $stat = self::quietly(static fn () => file_get_contents("/proc/{$pid}/stat"));

            return $stat === false ? null : (substr($stat, (int) strrpos($stat, ')') + 2, 1) ?: null);
        }
        if (!self::canExec()) {
            return null;
        }
        $out = [];
        @exec('ps -o stat= -p ' . $pid . ' 2>/dev/null', $out);
        $state = trim($out[0] ?? '');

        return $state === '' ? null : $state[0];
    }

    /**
     * Runs $read with no error handler to see its warning: a read of a
     * process that has just exited fails, and that's expected. Silencing it
     * with @ isn't enough, because the error handler is still called and Pest
     * reports what it's given.
     *
     * @internal
     * @template T
     * @param callable(): T $read
     * @return T
     */
    public static function quietly(callable $read): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Whether the ps, pgrep and kill commands can run: not on Windows, where
     * cmd can't open /dev/null and says so on stderr, and not with exec()
     * disabled, when calling it throws rather than failing.
     */
    private static function canExec(): bool
    {
        return PHP_OS_FAMILY !== 'Windows' && function_exists('exec');
    }
}
