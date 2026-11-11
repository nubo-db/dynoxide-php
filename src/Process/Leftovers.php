<?php

declare(strict_types=1);

namespace Dynoxide\Process;

/**
 * Clears up after a PHP process that died without stopping its server. A
 * fatal error runs no destructors, and an earlier shutdown function that
 * exits (as PHPUnit's does) skips the rest, so neither stops the server nor
 * deletes its output directory. A server started with --exit-with-parent
 * stops by itself once PHP has gone; an older one keeps running.
 *
 * Each output directory records the PHP process that owns it and the server
 * with everything it started, each with its start time. The next start looks
 * at the others, and for any whose owner has gone, stops what's still the
 * process recorded and deletes the directory.
 *
 * A record is judged only under the clock it was written with (the same boot
 * and process ID namespace on Linux, ps on the same host elsewhere): a /tmp
 * shared with a container would otherwise make every owner look dead. Linux
 * and macOS only, with the posix extension, so only this user's directories
 * are touched.
 *
 * @internal
 */
final class Leftovers
{
    private const FILE = 'owner.json';

    /**
     * Records who owns $dir, and the server's process tree once there is one.
     *
     * @param array<int, string> $server process ID to start time
     */
    public static function record(string $dir, array $server = []): void
    {
        $clock = ProcessTree::clock();
        $owner = getmypid();
        $start = ProcessTree::startTime($owner);
        if ($clock === null || $start === null) {
            return;
        }
        self::write($dir, ['clock' => $clock, 'owner' => [$owner, $start], 'server' => $server]);
    }

    /** Stops and deletes what dead owners left under $root. */
    public static function sweep(string $root, float $graceSeconds, bool $windows = PHP_OS_FAMILY === 'Windows'): void
    {
        if ($windows || !function_exists('posix_geteuid')) {
            return;
        }
        $clock = ProcessTree::clock();
        if ($clock === null || ProcessTree::startTime(getmypid()) === null) {
            return;
        }
        $user = posix_geteuid();
        foreach (@glob(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'dynoxide-*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            if (!preg_match('/^dynoxide-[0-9a-f]{16}$/', basename($dir)) || is_link($dir) || @fileowner($dir) !== $user) {
                continue;
            }
            $record = self::read($dir);
            // No record yet: being set up, or not ours to judge.
            if ($record === null || $record['clock'] !== $clock) {
                continue;
            }
            // Given up on before; cleared once it has gone by itself.
            if ($record['abandoned']) {
                if (ProcessTree::running($record['server']) === []) {
                    ServerProcess::removeDir($dir);
                }
                continue;
            }
            if (ProcessTree::startTime($record['owner'][0]) === $record['owner'][1]) {
                continue;
            }
            if (!self::stop($record['server'], $graceSeconds)) {
                // Couldn't be stopped: don't make every later start wait on it.
                $record['abandoned'] = true;
                self::write($dir, $record);
                continue;
            }
            ServerProcess::removeDir($dir);
        }
    }

    /** @param array<string, mixed> $record */
    private static function write(string $dir, array $record): void
    {
        // Written aside then renamed, so a sweep never reads half of it.
        $file = $dir . DIRECTORY_SEPARATOR . self::FILE;
        $temp = $file . '.' . getmypid();
        if (@file_put_contents($temp, json_encode($record)) !== false && !@rename($temp, $file)) {
            @unlink($temp);
        }
    }

    /** @return array{clock: string, owner: array{int, string}, server: array<int, string>, abandoned: bool}|null */
    private static function read(string $dir): ?array
    {
        $data = json_decode((string) @file_get_contents($dir . DIRECTORY_SEPARATOR . self::FILE), true);
        if (!is_array($data) || !is_string($data['clock'] ?? null) || !is_array($data['server'] ?? null)) {
            return null;
        }
        $owner = $data['owner'] ?? null;
        if (!is_array($owner) || !self::valid($owner[0] ?? null, $owner[1] ?? null)) {
            return null;
        }
        $server = [];
        foreach ($data['server'] as $pid => $start) {
            if (!self::valid(is_numeric($pid) ? (int) $pid : null, $start)) {
                return null;
            }
            $server[(int) $pid] = $start;
        }

        return ['clock' => $data['clock'], 'owner' => [$owner[0], $owner[1]], 'server' => $server, 'abandoned' => ($data['abandoned'] ?? false) === true];
    }

    private static function valid(mixed $pid, mixed $start): bool
    {
        return is_int($pid) && $pid > 0 && is_string($start) && $start !== '';
    }

    /**
     * Stops the recorded server and what it has started since, and says
     * whether all of it has gone.
     *
     * @param array<int, string> $tree
     */
    private static function stop(array $tree, float $graceSeconds): bool
    {
        if (ProcessTree::running($tree) === []) {
            return true;
        }
        foreach (array_keys(ProcessTree::running($tree)) as $pid) {
            $tree = ProcessTree::of($pid) + $tree;
        }
        ProcessTree::signal($tree, 15);
        $deadline = microtime(true) + $graceSeconds;
        while (ProcessTree::running($tree) !== [] && microtime(true) < $deadline) {
            usleep(10_000);
        }
        ProcessTree::signal($tree, 9);
        $deadline = microtime(true) + 1.0;
        while (ProcessTree::running($tree) !== [] && microtime(true) < $deadline) {
            usleep(10_000);
        }

        return ProcessTree::running($tree) === [];
    }
}
