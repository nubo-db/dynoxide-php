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

    /** How long kill() waits for a level of the tree to stop before going on. */
    private const STOP_WAIT_SECONDS = 1.0;

    /**
     * The process and everything below it, parents first, each with its
     * start time. Read it before signalling anything: once a parent dies its
     * children are re-parented and can't be found from it. It's walked from
     * one reading of the process table, so it ends however fast the tree
     * forks, and there's no limit on its size. Linux and macOS only.
     *
     * @return array<int, string> process ID to start time
     */
    public static function of(int $pid): array
    {
        $table = self::table();
        $start = $table[$pid][2] ?? null;
        if ($start === null || $table[$pid][1] === 'Z') {
            return [];
        }
        $tree = [$pid => $start];
        $level = [$pid => $start];
        while ($level !== []) {
            $level = array_diff_key(self::childrenIn($table, $level), $tree);
            $tree += $level;
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
     * Kills the process and everything below it, a level of the tree at a
     * time. Each level is stopped (SIGSTOP), and seen to have stopped, before
     * its children are read, so none can start another child between the
     * read and the kill. There's no limit on the tree's size. The walk does
     * stop after KILL_WALK_SECONDS, so a tree that forks faster than it can
     * be walked can't hold stop() for ever, and whatever has been found by
     * then is still killed. Linux and macOS only.
     */
    public static function kill(int $pid): void
    {
        $table = self::table();
        $start = $table[$pid][2] ?? null;
        if ($start === null) {
            return;
        }
        $found = [];
        $level = [$pid => $start];
        // hrtime, unlike microtime, doesn't jump when the clock is changed.
        $deadline = hrtime(true) + (int) (self::KILL_WALK_SECONDS * 1e9);
        while ($level !== [] && hrtime(true) < $deadline) {
            $found += $level;
            self::send($level, 'STOP', $table);
            $table = self::stopped($level);
            $next = array_diff_key(self::childrenIn($table, $level), $found);
            // Their children are known, so they can go now: nothing is left
            // stopped if this is cut short.
            self::send($level, 'KILL', $table);
            $level = $next;
        }
        self::send($found + $level, 'KILL', self::table());
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
     * Every process, as its parent, the first letter of its state (T when
     * stopped, Z for a zombie) and its start time: from /proc, or from one ps
     * call without it. Empty when neither can be read.
     *
     * @return array<int, array{int, string, string}>
     */
    private static function table(): array
    {
        $table = [];
        if (is_dir('/proc/self')) {
            foreach (self::quietly(static fn () => scandir('/proc')) ?: [] as $entry) {
                if (!ctype_digit($entry)) {
                    continue;
                }
                // A process can exit between the listing and the read.
                $stat = self::quietly(static fn () => file_get_contents("/proc/{$entry}/stat"));
                if ($stat === false) {
                    continue;
                }
                // The name in brackets can hold spaces; the fields after it don't.
                $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
                $table[(int) $entry] = [(int) ($fields[1] ?? 0), $fields[0] ?? 'Z', $fields[19] ?? ''];
            }

            return $table;
        }
        if (!self::canExec()) {
            return [];
        }
        $out = [];
        $status = 1;
        // The same locale and time zone as startTime(), so the start times match.
        @exec('LC_ALL=C TZ=UTC0 ps -A -o pid= -o ppid= -o stat= -o lstart= 2>/dev/null', $out, $status);
        if ($status !== 0) {
            return [];
        }
        foreach ($out as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(.+?)\s*$/', $line, $m) === 1) {
                $table[(int) $m[1]] = [(int) $m[2], $m[3][0], $m[4]];
            }
        }

        return $table;
    }

    /**
     * The live children, with their start times, of the processes in
     * $parents that are still the ones recorded.
     *
     * @param array<int, array{int, string, string}> $table
     * @param array<int, string> $parents
     * @return array<int, string>
     */
    private static function childrenIn(array $table, array $parents): array
    {
        $children = [];
        foreach ($table as $pid => [$parent, $state, $start]) {
            if ($state !== 'Z' && isset($parents[$parent]) && ($table[$parent][2] ?? null) === $parents[$parent]) {
                $children[$pid] = $start;
            }
        }

        return $children;
    }

    /**
     * Sends SIGSTOP or SIGKILL to each process in $tree that $table shows is
     * still the one recorded. SIGSTOP goes by name where its number isn't
     * known: it differs between Linux, the BSDs and other systems.
     *
     * @param array<int, string> $tree
     * @param array<int, array{int, string, string}> $table
     */
    private static function send(array $tree, string $signal, array $table): void
    {
        $pids = array_keys(array_filter($tree, static fn (string $start, int $pid): bool => ($table[$pid][2] ?? null) === $start && $table[$pid][1] !== 'Z', ARRAY_FILTER_USE_BOTH));
        if ($pids === []) {
            return;
        }
        $number = $signal === 'KILL' ? 9 : (defined('SIGSTOP') ? SIGSTOP : null);
        if ($number !== null && function_exists('posix_kill')) {
            foreach ($pids as $pid) {
                @posix_kill($pid, $number);
            }

            return;
        }
        if (self::canExec()) {
            @exec('kill -s ' . $signal . ' ' . implode(' ', $pids) . ' 2>/dev/null');
        }
    }

    /**
     * Waits, for up to STOP_WAIT_SECONDS, until every process in $tree has
     * stopped, gone or been replaced, then reads the process table again. A
     * stop signal only takes effect once a fork the process is in the middle
     * of has finished, and the new child can appear after a read of the table
     * made straight after the signal was sent. The read that sees the stop
     * can't be used for the children either: on Linux it lists the processes
     * before it reads their states, so a child can start between the two.
     *
     * @param array<int, string> $tree
     * @return array<int, array{int, string, string}>
     */
    private static function stopped(array $tree): array
    {
        $deadline = hrtime(true) + (int) (self::STOP_WAIT_SECONDS * 1e9);
        while (true) {
            $table = self::table();
            $waiting = array_filter($tree, static fn (string $start, int $pid): bool => ($table[$pid][2] ?? null) === $start && !in_array($table[$pid][1], ['T', 't', 'Z'], true), ARRAY_FILTER_USE_BOTH);
            if ($waiting === [] || hrtime(true) >= $deadline) {
                return self::table();
            }
            usleep(1_000);
        }
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
